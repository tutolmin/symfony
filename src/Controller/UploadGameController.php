<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\UploadGameType;
use App\Model\UploadGameTask;
use App\Security\UserVoter;
use App\Service\UploadGameTaskManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * UploadGameController.
 */
class UploadGameController extends AbstractController
{
    private $uploadGameTaskManager;

    /**
     * @param UploadGameTaskManager $uploadGameTaskManager
     */
    public function __construct(UploadGameTaskManager $uploadGameTaskManager)
    {
        $this->uploadGameTaskManager = $uploadGameTaskManager;
    }

    /**
     * @param Request $request
     *
     * @Route("/uploadGame", methods={"POST"})
     *
     * @return JsonResponse
     */
    public function uploadGame(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserVoter::CAN_UPLOAD);

        $form = $this->createForm(UploadGameType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadGameTask $uploadGameTask */
            $uploadGameTask = $form->getData();
            $uploadGameTaskEntity = $this->uploadGameTaskManager->createEntity($uploadGameTask);

            // @todo: handle all exceptions
            // @todo: implements unpack, parsing

            return new JsonResponse(sprintf('Successfully uploaded with `%s`', $uploadGameTaskEntity->getHash()));
        }

        // @todo: need checked
        return new JsonResponse($form->getErrors()->current()->getMessage(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param string  $taskId
     * @param Request $request
     *
     * @Route("/uploadGame/{taskId}/status", methods={"GET"}, requirements={"taskId":"\d"})
     *
     * @return Response
     */
    public function getUploadingGameStatus(string $taskId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::CAN_UPLOAD);

        return new Response('check status');
    }
}
