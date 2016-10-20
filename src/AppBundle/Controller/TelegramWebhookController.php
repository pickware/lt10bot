<?php

namespace AppBundle\Controller;

use AppBundle\Telegram\MenuPublisher;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookController extends Controller
{
    /**
     * This webhook endpoints receives all events (Messages, Bot added, ...) from Telegram.
     * @Route("/webhook")
     */
    public function webhookAction(Request $request)
    {
        $logger = $this->get('logger');
        $json = $request->getContent();
        $update = json_decode($json);

        $logger->info('Webhook request received:', [
            'method' => $request->getMethod(),
            'headers' => $request->headers
        ]);

        if (property_exists($update, 'callback_query')) {
            $menuPublisher = new MenuPublisher($this->get('logger'));
            $menuPublisher->answerCallbackQuery($update->callback_query);
        }

        $logger->info(json_encode($update));
        return new Response(Response::HTTP_NO_CONTENT);
    }
}
