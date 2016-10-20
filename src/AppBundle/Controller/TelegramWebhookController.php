<?php

namespace AppBundle\Controller;

use DateTime;
use AppBundle\Entity\Reservation;
use AppBundle\Scraper\LT10Service;
use AppBundle\Telegram\TelegramService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookController extends Controller
{
    const MAX_RESERVATIONS = 5;

    /**
     * This webhook endpoints receives all events (Messages, Bot added, ...) from Telegram.
     * @Route("/webhook")
     * @param Request $request
     * @return Response
     */
    public function webhookAction(Request $request)
    {
        $this->logWebhookRequest($request);

        $update = json_decode($request->getContent());
        if (property_exists($update, 'callback_query')) {
            $this->handleReservation($update->callback_query);
        } else if (property_exists($update, 'message') && property_exists($update->message, 'chat')) {
            $this->handleMessage($update->message->text, $update->message->chat->id);
        }

        return new Response(Response::HTTP_NO_CONTENT);
    }

    private function logWebhookRequest(Request $request)
    {
        $logger = $this->get('logger');
        $logger->info('Webhook request received:', [
            'method' => $request->getMethod(),
            'headers' => $request->headers
        ]);
        $logger->info($request->getContent());
    }

    private function handleReservation($callbackQuery)
    {
        $logger = $this->get('logger');
        $notificationText = $this->handleReservationCase($callbackQuery);
        $menuPublisher = new TelegramService($logger);
        $menuPublisher->answerCallbackQuery($callbackQuery, $notificationText);
    }

    private function handleReservationCase($callbackQuery)
    {
        $logger = $this->get('logger');
        $user = $callbackQuery->from->id;

        list($dish, $date) = $this->parseReservationToken($callbackQuery->data);
        $oldReservation = $this->findOldReservation($user, $date);

        $today = (new DateTime('now'))->format('Y-m-d');
        if ($today >= $date) {
            $logger->info("User ${user} tried to make, modify or cancel a reservation for ${date}, which is in the past.");
            return 'Ich kann die Reservierung jetzt nicht mehr ändern, sorry.';
        }

        if (!$dish) {
            return $this->handleCancellation($user, $date, $oldReservation);
        }
        return $this->handleMakeOrUpdateReservation($user, $date, $dish, $oldReservation);
    }

    private function parseReservationToken($token)
    {
        list($dish, $date) = explode('_', $token);
        if ($dish == 'none') {
            $dish = null;
        }
        return [$dish, $date];
    }

    private function handleCancellation($user, $date, $oldReservation)
    {
        $this->get('logger')->info("user ${user} canceled their reservation for ${date}.");
        if (!$oldReservation) {
            return 'Okay, vielleicht beim nächsten Mal :)';
        } else {
            $this->deleteReservation($oldReservation);
            return 'Okay, ich habe dein Gericht abbestellt.';
        }
    }

    private function handleMakeOrUpdateReservation($user, $date, $dish, $oldReservation)
    {
        $logger = $this->get('logger');
        $logger->info("user ${user} wants to reserve dish ${dish} for ${date}.");
        $numberOfExistingReservations = $this->getNumberOfReservations($date, $dish);

        if ($numberOfExistingReservations >= static::MAX_RESERVATIONS) {
            $logger->warn("Max reservations reached for dish ${dish} on date ${date}.");
            return ('Sorry, das musst du manuell reservieren.');
        }

        if ($oldReservation && $oldReservation->getDish() != $dish) {
            $oldDish = $oldReservation->getDish();
            $logger->info("user ${user} is updating his reservation from ${oldDish} to ${dish} for ${date}.");
            $this->deleteReservation($oldReservation);
            $this->recordReservation($user, $date, $dish);
            return 'Okay, ich habe deine Reservierung aktualisiert!';
        }

        if ($oldReservation) {
            return 'Das hatte ich dir schon bestellt. Scheinst dich ja sehr darauf zu freuen! :)';
        }

        $this->recordReservation($user, $date, $dish);
        return 'Cool, dein Gericht ist bestellt!';
    }

    private function recordReservation($user, $date, $dish)
    {
        $reservation = new Reservation();
        $reservation->setUserId($user);
        $reservation->setMenuDate($date);
        $reservation->setDish($dish);
        $em = $this->getDoctrine()->getManager();
        $em->persist($reservation);
        $em->flush();
        $this->updateReservations($date, $dish);
    }

    private function deleteReservation($oldReservation)
    {
        $em = $this->getDoctrine()->getManager();
        $em->remove($oldReservation);
        $em->flush();
        $this->updateReservations($oldReservation->getMenuDate(), $oldReservation->getDish());
    }

    private function updateReservations($date, $dish)
    {
        $numReservations = $this->getNumberOfReservations($date, $dish);
        $lt10service = new LT10Service($this->get('logger'));
        $lt10service->updateDishReservations($date, $dish, $numReservations);
    }

    private function findOldReservation($user, $date)
    {
        return $this->getDoctrine()->getRepository('AppBundle:Reservation')->findOldReservation($user, $date);
    }

    private function getNumberOfReservations($date, $dish)
    {
        return $this->getDoctrine()->getRepository('AppBundle:Reservation')->getNumberOfReservations($date, $dish);
    }

    private function handleMessage($text, $chatId) {
        $logger = $this->get('logger');
        $expectedChatId = getenv('TELEGRAM_CHAT_ID');
        if ($chatId != $expectedChatId) {
            $logger->warn("Command received from illegal chat {$chatId} (expected: ${expectedChatId}).");
            return;
        }
        $logger->info("Processing message ${text}.");
        if ($text === '/menu' || $text === '/menu@LT10Bot') {
            $logger->warn("Command received from illegal chat {$chatId}.");
            $logger->info("Menu requested in chat {$chatId}.");
            CheckMenuController::fetchAndShowMenu($logger);
        }
    }
}
