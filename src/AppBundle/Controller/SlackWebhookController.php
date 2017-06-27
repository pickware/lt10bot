<?php
namespace AppBundle\Controller;

use AppBundle\Entity\Dish;
use AppBundle\Service\ReservationService;
use AppBundle\Service\SlackBotService;
use Doctrine\ORM\EntityManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SlackWebhookController extends Controller
{
    /** @var SlackBotService */
    private $slackBotService;

    /** @var EntityManager */
    private $entityManager;

    /** @var ReservationService */
    private $reservationService;

    /**
     * @Route("/slack/action-endpoint")
     * @param Request $request
     * @return Response
     */
    public function actionEndpointAction(Request $request, SlackBotService $slackBotService, EntityManager $entityManager, ReservationService $reservationService)
    {
        $this->slackBotService = $slackBotService;
        $this->entityManager = $entityManager;
        $this->reservationService = $reservationService;

        $this->logWebhookRequest($request);

        $message = json_decode($request->get('payload'), true);
        foreach ($message['actions'] as $action) {
            if ($action['type'] === 'select') {
                try {
                    $dish = $this->handleReservation($action, $message);
                    $response = $slackBotService->updateMessage($message, $dish);
                } catch (\InvalidArgumentException $e) {
                    $response = [
                        'response_type' => 'ephemeral',
                        'replace_original' => false,
                        'text' => $e->getMessage()
                    ];
                }

                return new Response(
                    json_encode($response),
                    Response::HTTP_OK,
                    [
                        'Content-Type' => 'application/json'
                    ]
                );
            }
        }

        return new Response(Response::HTTP_OK);
    }

    /**
     * Handles when a user clicks on one of the menu options of the inline keyboard shown in the menu message.
     * Then sends a confirmation of the callback query along with a notification message for the user.
     *
     * @param $action
     * @param $message
     * @return Dish|null
     */
    private function handleReservation(array $action, array $message)
    {
        $dish = $this->entityManager->find(Dish::class, intval($message['callback_id']));
        $userId = $message['user']['id'];
        $userName = $message['user']['name'];

        $desiredAmount = intval($action['selected_options'][0]['value']);

        $this->reservationService->makeReservation($dish, $desiredAmount, $userId, $userName);

        return $dish;
    }

    /**
     * Logs a webhook request to the application log.
     * @param Request $request the request to log
     */
    private function logWebhookRequest(Request $request)
    {
        $logger = $this->get('logger');
        $payload = $request->get('payload');
        $json = json_decode($payload, true);
        $logger->info(
            'Webhook request received:',
            [
                'method' => $request->getMethod(),
                'headers' => $request->headers,
                'json' => $json
            ]
        );
    }
}
