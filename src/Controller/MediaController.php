<?php

namespace App\Controller;

use App\Exceptions\MediaNotFoundException;
use App\Exceptions\ProviderNotRegisteredException;
use App\Providers\AnimeFireProvider;
use App\Services\MediaService;
use App\Support\ResponseSupport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MediaController
{
    private MediaService $mediaService;

    public function __construct()
    {
        $this->mediaService = new MediaService();
    }

    /**
     * Display a list of episodes.
     *
     * @return string
     *
     * @throws ProviderNotRegisteredException
     */
    #[Route('/episode/{slug}/{season}/{episodeNumber}', name: 'episodes', methods: ['GET'])]
    public function episode(string $slug, int $season, int $episodeNumber): Response
    {
        return ResponseSupport::json(
            $this->mediaService->searchEpisode($episodeNumber, $season, $slug)
        );
    }

    /**
     * Lista episódios (AnimeFire): número, temporada, título PT e áudio.
     * Usado pelo modo "Sugoi primeiro" para montar a grade sem a Anivexa.
     */
    #[Route('/list/{slug}', name: 'list', methods: ['GET'])]
    public function list(string $slug): Response
    {
        $episodes = (new AnimeFireProvider())->listEpisodes($slug);
        if (!$episodes) {
            throw new MediaNotFoundException(message: 'Not Found');
        }

        return ResponseSupport::json(['episodes' => $episodes]);
    }
}
