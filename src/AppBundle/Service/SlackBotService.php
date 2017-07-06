<?php
namespace AppBundle\Service;

use AppBundle\Entity\Dish;
use AppBundle\Entity\Reservation;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use IntlDateFormatter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Provides integration with the Slack chat service.
 */
class SlackBotService
{
    const LOCALE = 'de_DE';

    /** @var Client $client */
    private $client;

    /** @var string $slackApiToken */
    private $slackApiToken;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->slackApiToken = getenv('SLACK_API_TOKEN');
        if (!$this->slackApiToken) {
            throw new \Exception('SLACK_API_TOKEN must be set');
        }
        $this->channel = getenv('SLACK_CHANNEL');
        if (!$this->channel) {
            throw new \Exception('SLACK_CHANNEL must be set');
        }

        $this->client = new Client(['base_uri' => 'https://slack.com/api/']);
    }

    /**
     * Publish a menu to the group chat.
     *
     * @param Dish[] $dishes the dishes of the menu, as returned by LT10Service::getDishesForDate
     * @param DateTime $date the date the menu is valid for
     */
    public function publishMenu(array $dishes, $date)
    {
        $dateFormatter = IntlDateFormatter::create(static::LOCALE, IntlDateFormatter::SHORT, IntlDateFormatter::NONE);
        $dateFormatter->setPattern('EEEE, \'den\' d. MMMM');
        $localeFormattedDate = $dateFormatter->format($date);
        $isoFormattedDate = $date->format('Y-m-d');
        $this->logger->info("isoFormattedDate = ${isoFormattedDate}");
        $attachments = [];
        if (empty($dishes)) {
            $text = "Für ${localeFormattedDate} habe ich leider kein Menü gefunden \u{1f625}";
        } else {
            $text = "Am ${localeFormattedDate} gibt es folgende Gerichte im LT10:\n";
            $attachments = array_map(
                function ($dish) {
                    return $this->makeDishAttachment($dish);
                },
                $dishes
            );
        }
        $this->client->post(
            'chat.postMessage',
            [
                'form_params' => [
                    'token' => $this->slackApiToken,
                    'channel' => $this->channel,
                    'text' => $text,
                    'response_type' => 'in_channel',
                    'as_user' => false,
                    'username' => 'LT10 Bot',
                    'icon_emoji' => ':fork_and_knife:',
                    'attachments' => json_encode($attachments)
                ]
            ]
        );
    }

    /**
     * @param array $message
     * @param Dish $dish
     * @return mixed
     */
    public function updateMessage(array $message, Dish $dish)
    {
        $updatedMessage = $message['original_message'];
        foreach ($updatedMessage['attachments'] as $attachmentIndex => $attachment) {
            if (intval($attachment['callback_id']) === $dish->getId()) {
                $updatedMessage['attachments'][$attachmentIndex] = $this->makeDishAttachment($dish);
            }
        }

        return $updatedMessage;
    }

    /**
     * @param Dish $dish
     * @return array
     */
    private function makeDishAttachment(Dish $dish)
    {
        $colors = ['#4cd964', '#34aadc', '#5856d6'];

        $text = sprintf(
            "%s (gekocht von %s)\nPreis: %.2f €",
            $dish->getDescription(),
            $dish->getCook(),
            $dish->getPrice()
        );

        /** @var Reservation $reservation */
        foreach ($dish->getReservations() as $reservation) {
            $text .= "\n@" . $reservation->getUserName();
            if ($reservation->getAmount() > 1) {
                $text .= " (x" . $reservation->getAmount() . ")";
            }
        }

        return [
            'text' => $text,
            'fallback' => 'Ich kann leider nicht für dich reservieren :(',
            'callback_id' => (string)$dish->getId(),
            'color' => $colors[$dish->getId() % count($colors)],
            'attachment_type' => 'default',
            'actions' => [
                [
                    'name' => 'dish_amount',
                    'text' => 'Anzahl auswählen...',
                    'type' => 'select',
                    'options' => array_map(
                        function ($amount) {
                            return [
                                'text' => ($amount === 0) ? "Nein danke" : "$amount",
                                'value' => "$amount"
                            ];
                        },
                        range(0, 5)
                    )
                ]
            ]
        ];
    }

    /**
     * Sends a lunch reminder to the person who made a reservation.
     * @param Reservation $reservation the reservation to send a reminder for
     */
    public function sendLunchReminder($reservation)
    {
        $userName = $reservation->getUserName();
        try {
            $dishDescription = $reservation->getDish()->getDescription();
            $this->client->post(
                'chat.postMessage',
                [
                    'form_params' => [
                        'token' => $this->slackApiToken,
                        'channel' => '@' . $reservation->getUserName(),
                        'text' =>
                            "Hallo ${userName}! Du hast für heute \"${dishDescription}\" im LT10 bestellt. "
                            . "Vergiss nicht, essen zu gehen! \u{1f37D} \u{1f642}",
                        'response_type' => 'in_channel',
                        'as_user' => false,
                        'username' => 'LT10 Bot',
                        'icon_emoji' => ':fork_and_knife:'
                    ]
                ]
            );
        } catch (ClientException $e) {
            $this->logger->info("Cannot deliver lunch reminder to user ${userName}");
        }
    }
}
