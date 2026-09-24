<?php

namespace App\Providers;

use App\Providers\Contracts\HandleResponseInterface;
use App\Providers\Contracts\MediaProviderInterface;
use App\Providers\Contracts\MediaProviderPropertiesInterface;
use App\Providers\Contracts\MediaProviderRulesInterface;
use App\Support\Traits\SearchEngine;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * AnimeFire (BR) via API interna do site novo (api.animefire.one).
 * Fluxo: pesquisar -> detalhe do anime (episódios) -> episódio (streams
 * dublado/legendado). Sem scraping de HTML.
 */
class AnimeFireProvider implements MediaProviderInterface, MediaProviderPropertiesInterface, MediaProviderRulesInterface, HandleResponseInterface
{
    use SearchEngine;

    public const API = 'https://api.animefire.one';

    public function name(): string
    {
        return 'Anime Fire';
    }

    public function slug(): string
    {
        return 'anime-fire';
    }

    public function baseUrl(): string
    {
        return self::API.'/';
    }

    public function isEmbed(): bool
    {
        return false;
    }

    public function hasAds(): bool
    {
        return false;
    }

    public function searchRequestMethod(): string
    {
        return 'GET';
    }

    public function getSearchEpisodeEndpoint(int $episode, int $season, string $slug): string
    {
        return self::API.'/animes/pesquisar?q='.urlencode($slug);
    }

    public function canUsePrefix(): bool
    {
        return false;
    }

    public function canUseSuffix(): bool
    {
        return false;
    }

    public function mustSerializeEpisode(): bool
    {
        return false;
    }

    public function mustHandleResponse(): bool
    {
        return true;
    }

    public function responseHasError(ResponseInterface $response): bool
    {
        return $response->getStatusCode() >= 400;
    }

    public function handleResponse(ResponseInterface $response): string
    {
        return '';
    }

    private function client(): Client
    {
        return new Client([
            'base_uri' => self::API,
            'timeout' => 15,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
                'Referer' => 'https://animefire.one/',
            ],
        ]);
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s ?: ''));
    }

    /** Escolhe o anime: título exato > contém > primeiro. */
    private function pickAnime(array $items, string $slug): ?array
    {
        if (!$items) {
            return null;
        }
        $want = $this->norm($slug);
        $best = null;
        $bestScore = -1;
        foreach ($items as $item) {
            $titles = array_values(array_filter($item['titles'] ?? []));
            $titles[] = (string) ($item['id'] ?? '');
            foreach ($titles as $t) {
                $n = $this->norm((string) $t);
                if (!$n) {
                    continue;
                }
                $score = $n === $want ? 100 : (str_contains($n, $want) || str_contains($want, $n) ? 50 : 0);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $item;
                }
            }
        }
        return $bestScore > 0 ? $best : $items[0];
    }

    private function fetchAnime(Client $client, string $animeId): ?array
    {
        $res = $client->get('/anime/'.$animeId);
        if ($res->getStatusCode() >= 400) {
            return null;
        }
        $json = json_decode((string) $res->getBody(), true);
        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /** Lista episódios: [{id, season, number, title, audio}]. */
    public function listEpisodes(string $slug): array
    {
        $client = $this->client();
        $res = $client->get('/animes/pesquisar', ['query' => ['q' => $slug]]);
        if ($res->getStatusCode() >= 400) {
            return [];
        }
        $json = json_decode((string) $res->getBody(), true);
        $anime = $this->pickAnime($json['data'] ?? [], $slug);
        if (!$anime) {
            return [];
        }
        $detail = $this->fetchAnime($client, (string) $anime['id']);
        $out = [];
        foreach (($detail['episodes'] ?? []) as $ep) {
            $n = (int) ($ep['number'] ?? 0);
            if ($n < 1) {
                continue;
            }
            $out[] = [
                'id' => (string) ($ep['id'] ?? ''),
                'season' => (int) ($ep['season'] ?? 1),
                'number' => $n,
                'title' => (string) ($ep['title'] ?? ('Episódio '.$n)),
                'audio' => (string) ($ep['audio'] ?? ''),
            ];
        }
        usort($out, fn ($a, $b) => [$a['season'], $a['number']] <=> [$b['season'], $b['number']]);
        return $out;
    }

    private function pickEpisode(array $episodes, int $season, int $number): ?array
    {
        foreach ($episodes as $ep) {
            if ((int) ($ep['season'] ?? 1) === $season && (int) ($ep['number'] ?? 0) === $number) {
                return $ep;
            }
        }
        // Sem a temporada exata: qualquer entrada com esse número.
        foreach ($episodes as $ep) {
            if ((int) ($ep['number'] ?? 0) === $number) {
                return $ep;
            }
        }
        return null;
    }

    /** Escolhe stream: ?audio=dub -> dublado; senão legendado; senão o 1º com URL. */
    private function pickStream(array $streams): ?string
    {
        if (!$streams) {
            return null;
        }
        $wantDub = 'dub' === strtolower((string) $this->parameter('audio'));
        $usable = array_values(array_filter($streams, fn ($s) => !empty($s['url']) && empty($s['is_offline'])));
        if (!$usable) {
            foreach ($streams as $s) {
                if (!empty($s['url'])) {
                    return (string) $s['url'];
                }
            }
            return null;
        }
        $want = $wantDub ? 'dublado' : 'legendado';
        foreach ($usable as $s) {
            if (str_contains(strtolower((string) ($s['audio'] ?? '')), $want)) {
                return (string) $s['url'];
            }
        }
        return (string) $usable[0]['url'];
    }

    private function err(string $endpoint): array
    {
        return ['error' => true, 'searched_endpoint' => $endpoint, 'episode' => null];
    }

    public function searchEpisode(int $episodeNumber, int $season, string $slug): array
    {
        $endpoint = self::API.'/animes/pesquisar?q='.urlencode($slug);
        try {
            $client = $this->client();
            $res = $client->get('/animes/pesquisar', ['query' => ['q' => $slug]]);
            if ($res->getStatusCode() >= 400) {
                return [$this->err($endpoint)];
            }
            $json = json_decode((string) $res->getBody(), true);
            $anime = $this->pickAnime($json['data'] ?? [], $slug);
            if (!$anime) {
                return [$this->err($endpoint)];
            }
            $detail = $this->fetchAnime($client, (string) $anime['id']);
            $ep = $this->pickEpisode($detail['episodes'] ?? [], $season, $episodeNumber);
            if (!$ep || empty($ep['id'])) {
                return [$this->err($endpoint)];
            }
            $res = $client->get('/episode/'.$ep['id']);
            if ($res->getStatusCode() >= 400) {
                return [$this->err($endpoint)];
            }
            $epj = json_decode((string) $res->getBody(), true);
            $url = $this->pickStream($epj['data']['streams'] ?? []);
            if (!$url) {
                return [$this->err($endpoint)];
            }
            return [[
                'error' => false,
                'searched_endpoint' => self::API.'/episode/'.$ep['id'],
                'episode' => $url,
            ]];
        } catch (\Throwable) {
            return [$this->err($endpoint)];
        }
    }
}
