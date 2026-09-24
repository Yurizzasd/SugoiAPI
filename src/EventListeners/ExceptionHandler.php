<?php

namespace App\EventListeners;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExceptionHandler
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $response = new JsonResponse();

        if ($exception instanceof HttpExceptionInterface) {
            $response->setData([
                'error' => true,
                'message' => $exception->getMessage(),
                'status' => $exception->getStatusCode(),
            ]);
        } else {
            // DEBUG TEMPORÁRIO: sempre expõe a exceção real.
            $response->setData([
                'error' => true,
                'message' => [
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
            ]);
        }

        $event->setResponse($response);
    }
}
