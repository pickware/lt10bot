<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class IncomingWebhookController extends Controller
{
    /**
     * @Route("/webhook")
     */
    public function webhookAction(Request $request)
    {
        $logger = $this->get('logger');
        $json = $request->getContent();

        $logger->info('Webhook request received:', [
            'method' => $request->getMethod(),
            'headers' => $request->headers,
            'body' => $json
        ]);
        return new Response(Response::HTTP_NO_CONTENT);
    }
}
