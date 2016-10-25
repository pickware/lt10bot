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

    /**
     * Logs a webhook request to the application log.
     * @param Request $request the request to log
     */
    private function logWebhookRequest(Request $request)
    {
        $logger = $this->get('logger');
        $logger->info('Webhook request received:', [
            'method' => $request->getMethod(),
            'headers' => $request->headers
        ]);
        $logger->info($request->getContent());
    }

    /**
     * Handles when a user clicks on one of the menu options of the inline keyboard shown in the menu message.
     * Then sends a confirmation of the callback query along with a notification message for the user.
     * @param array $callbackQuery the callback query part of the webhook event
     */
    private function handleReservation($callbackQuery)
    {
        $logger = $this->get('logger');
        list($dish, $date, $numDishes) = $this->parseReservationToken($callbackQuery->data);
        $notificationText = $this->handleReservationCase($callbackQuery, $dish, $date);
        $telegramService = new TelegramService($logger, $this->getDoctrine()->getRepository('AppBundle:Reservation'));
        $telegramService->answerCallbackQuery($callbackQuery, $notificationText);
        $telegramService->updateReservationCounts($callbackQuery, $date, $numDishes);
    }

    /**
     * Decide which reservation case we have, i.e. reserve dish, change dish or cancel
     * @param array $callbackQuery the callback query part of the webhook event
     * @return string a notification message to show to the Telegram user
     */
    private function handleReservationCase($callbackQuery, $dish, $date)
    {
        $logger = $this->get('logger');
        $user = $callbackQuery->from->id;
        $userName = $callbackQuery->from->first_name;

        $oldReservation = $this->findOldReservation($user, $date);

        $lt10service = new LT10Service($this->get('logger'));
        $canUpdate = $dish || $oldReservation ? $lt10service->canUpdateDishReservations($date, $dish ?: $oldReservation->getDish()) : true;
        if (!$canUpdate) {
            $logger->info("User ${user} tried to make, modify or cancel a reservation for ${date}, "
                . "but reservations were already closed.");
            return 'Ich kann die Reservierung jetzt nicht mehr ändern, sorry.';
        }

        if (!$dish) {
            return $this->handleCancellation($user, $date, $oldReservation);
        }
        return $this->handleMakeOrUpdateReservation($user, $userName, $date, $dish, $oldReservation);
    }

    /**
     * Takes a reservation token of the form ${dish}_${date} and parses it.
     * @param string $token the token to parse
     * @return array an array [dish, date, numDishes], where dish is null if we have a cancellation
     */
    private function parseReservationToken($token)
    {
        list($dish, $date, $numDishes) = explode('_', $token);
        if ($dish == 'none') {
            $dish = null;
        }
        return [$dish, $date, $numDishes];
    }

    /**
     * Cancel a dish reservation.
     * @param string $user the user who wants to cancel their reservation
     * @param string $date the date for which to cancel
     * @param Reservation $oldReservation the old reservation entity from the database
     * @return string a notification to show to the Telegram user
     */
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

    /**
     * Make a new reservation or change the dish of an existing reservation.
     * @param string $user the user who wants to change their reservation
     * @param string $userName the friendly name of the user
     * @param string $date the date for which to change the reservation
     * @param string $dish the new dish to set
     * @param Reservation $oldReservation the old reservation entity from the database
     * @return string a notification to show to the Telegram user
     */
    private function handleMakeOrUpdateReservation($user, $userName, $date, $dish, $oldReservation)
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
            $this->recordReservation($user, $userName, $date, $dish);
            return 'Okay, ich habe deine Reservierung aktualisiert!';
        }

        if ($oldReservation) {
            return 'Das hatte ich dir schon bestellt. Scheinst dich ja sehr darauf zu freuen! :)';
        }

        $this->recordReservation($user, $userName, $date, $dish);
        return 'Cool, dein Gericht ist bestellt!';
    }

    /**
     * Saves a new reservation to the database.
     * @param string $user the user who is reserving
     * @param string $userName the name of the user who is reserving, used for the reminder message
     * @param string $date the date of the reservation
     * @param string $dish the reserved dish
     */
    private function recordReservation($user, $userName, $date, $dish)
    {
        $reservation = new Reservation();
        $reservation->setUserId($user);
        $reservation->setUserName($userName);
        $reservation->setMenuDate($date);
        $reservation->setDish($dish);
        $em = $this->getDoctrine()->getManager();
        $em->persist($reservation);
        $em->flush();
        $this->updateReservations($date, $dish);
    }

    /**
     * Delete a reservation
     * @param Reservation $oldReservation the reservation to delete
     */
    private function deleteReservation($oldReservation)
    {
        $em = $this->getDoctrine()->getManager();
        $em->remove($oldReservation);
        $em->flush();
        $this->updateReservations($oldReservation->getMenuDate(), $oldReservation->getDish());
    }

    /**
     * Checks the current reservation total and calls the LT10 service to update this.
     * @param string $date the date for which to update the number of reservations
     * @param string $dish the dish for which to update
     * @throws \AppBundle\Scraper\LT10ServiceException
     */
    private function updateReservations($date, $dish)
    {
        $numReservations = $this->getNumberOfReservations($date, $dish);
        $lt10service = new LT10Service($this->get('logger'));
        $lt10service->updateDishReservations($date, $dish, $numReservations);
    }

    /**
     * Find any existing reservations for a user.
     * @param string $user the user for which to find reservations
     * @param string $date the date on which to look for reservations
     * @return Reservation|null the old reservation if existing, null otherwise
     */
    private function findOldReservation($user, $date)
    {
        return $this->getDoctrine()->getRepository('AppBundle:Reservation')->findOldReservation($user, $date);
    }

    /**
     * Count how many people have reserved a dish on a particular day.
     * @param string $date the date for which to check reservations
     * @param string $dish the dish to count
     * @return integer the number of reservations
     */
    private function getNumberOfReservations($date, $dish)
    {
        return $this->getDoctrine()->getRepository('AppBundle:Reservation')->getNumberOfReservations($date, $dish);
    }

    /**
     * Handles a chat message received by the bot.
     * @param string $text the text of the message
     * @param string $chatId the id of the chat where the message was received
     */
    private function handleMessage($text, $chatId)
    {
        $logger = $this->get('logger');
        $expectedChatId = getenv('TELEGRAM_CHAT_ID');
        if ($chatId != $expectedChatId) {
            $logger->warn("Command received from illegal chat {$chatId} (expected: ${expectedChatId}).");
            return;
        }
        $parts = explode(' ', $text);
        $logger->info("Processing message ${text}.", ['parts' => $parts]);
        if (!empty($parts) && ($parts[0] === '/menu' || $parts[0] === '/menu@LT10Bot')) {
            $date = null;
            if (array_key_exists(1, $parts)) {
                $date = $parts[1];
            }
            $logger->warn("Command received from illegal chat {$chatId}.");
            $logger->info("Menu requested in chat {$chatId}.");
            CheckMenuController::fetchAndShowMenu($logger, $this->getDoctrine()->getRepository('AppBundle:Reservation'),
                $date);
        }
    }
}
