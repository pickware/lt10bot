<?php
namespace AppBundle\Service;

use AppBundle\Entity\Dish;
use Doctrine\ORM\EntityManager;
use Exception;
use DateTime;
use Goutte\Client;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class LT10Service
 *
 * Handles communication with the functionality of http://kantine.lt10.de/menus.
 */
class LT10Service
{
    const ENDPOINT = 'http://kantine.lt10.de/menu';
    private $client;
    private $userName;
    private $password;
    private $entityManager;

    public function __construct(Logger $logger, EntityManager $entityManager)
    {
        $this->logger = $logger;
        $this->userName = getenv('LT10_USER');
        $this->password = getenv('LT10_PASSWORD');
        if (!$this->userName) {
            throw new Exception('LT10_USER must be set');
        }
        if (!$this->password) {
            throw new Exception('LT10_PASSWORD must be set');
        }
        $this->entityManager = $entityManager;
    }

    /**
     * Update the total number of reservations for a single dish.
     *
     * @param Dish $dish the dish for which to change the number of reservations
     * @param int $desiredAmount
     */
    public function updateDishReservations(Dish $dish, $desiredAmount)
    {
        $this->client = new Client();
        $this->login();
        $submitButton = $this->crawler->selectButton('submit');
        $reservationForm = $submitButton->form();
        $reservationForm->setValues([
            $dish->getElementIdentifier() => $desiredAmount
        ]);
        $this->client->submit($reservationForm);
        $this->logger->info(
            sprintf(
                'Updated reservations of %s (%s) to a total of %d',
                $dish->getElementIdentifier(),
                $dish->getDescription(),
                $desiredAmount
            )
        );
    }

    /**
     * Checks whether reservations are still open for a particular date.
     * @param string $date the date of the reservation
     * @param string $dish the dish for which to change the number of reservations
     * @return bool true iff reservations can still be modified
     */
    public function canUpdateDishReservations($date, $dish)
    {
        $this->client = new Client();
        $this->login();
        $submitButton = $this->crawler->selectButton('submit');
        $reservationForm = $submitButton->form();

        return $reservationForm->has("${date}_${dish}_count");
    }

    /**
     * Scrape dishes for a date.
     * @param DateTime $date the date for which to scrape dishes
     * @return array the scraped dishes
     */
    public function getDishesForDate($date)
    {
        $dishRepository = $this->entityManager->getRepository(Dish::class);
        $dishes = $dishRepository->findBy(
            [
                'date' => $date
            ]
        );
        if (!empty($dishes)) {
            return $dishes;
        }

        // No dishes in the DB for that date - try to scrape them from the website
        $this->client = new Client();
        $this->login();
        $dishes = $this->parseDay($date);

        foreach ($dishes as $dish) {
            $this->entityManager->persist($dish);
        }
        $this->entityManager->flush($dishes);

        return $dishes;
    }

    /**
     * Login using the login form.
     */
    private function login()
    {
        $this->crawler = $this->client->request('GET', static::ENDPOINT);
        $loginButton = $this->crawler->selectButton('login');
        if ($loginButton->count() !== 0) {
            $loginForm = $loginButton->form();
            $loginForm->setValues(
                [
                    'u' => $this->userName,
                    'p' => $this->password
                ]
            );
            $this->crawler = $this->client->submit($loginForm);
        }
    }

    /**
     * Finds and parses the correct day (i.e. tomorrow)
     * @return array
     */
    private function parseDay($date)
    {
        $dateDescription = $this->getRelativeDateDescription($date);
        $this->logger->info("Filtering dishes for day ${dateDescription}.");
        $i = 0;

        return array_filter(
            $this->crawler
                ->filter('.day')
                ->reduce(
                    function (Crawler $day) use ($dateDescription) {
                        $date = $day->filter('p.date')->text();

                        return $date === $dateDescription;
                    }
                )
                ->filter('.menu')
                ->each(
                    function (Crawler $dish) use ($date, &$i) {
                        $result = $this->parseDish($dish, $date, $i);
                        $i++;

                        return $result;
                    }
                )
        );
    }

    /**
     * Returns a date formatted relatively to today as on http://kantine.lt10.de/menu
     *
     * @param DateTime $date the date to format
     * @return string "heute", "morgen" or a date formatted like "Thu, 27/Oct"
     */
    private function getRelativeDateDescription($date)
    {
        $today = new DateTime('today');
        $date->setTime(0, 0, 0);
        $dateDifference = $today->diff($date, false);

        // Make $daysBetween negative when $date is before $today
        $daysBetween = $dateDifference->days * (1 - 2 * $dateDifference->invert);
        $invert = $dateDifference->invert;
        $this->logger->info("date difference: ${daysBetween}, invert=${invert}.");
        switch ($daysBetween) {
            case 0:
                return 'heute';

            case 1:
                return 'morgen';

            default:
                return $date->format('D, j/M');
        }
    }

    /**
     * Parse description, cost, cooks and additional tags from a dish (i.e. div.menu html element)
     * @param Crawler $dish
     * @param DateTime $date
     * @param int $i
     * @return Dish|bool
     */
    private function parseDish(Crawler $dish, DateTime $date, int $i)
    {
        $result = new Dish();
        $result->setDate($date);

        $result->setElementIdentifier(
            sprintf(
                '%s_%s_count',
                $date->format('Y-m-d'),
                chr(ord('A') + $i)
            )
        );

        $dishChildNodes = $dish->getNode(0)->childNodes;
        if ($dishChildNodes->length <= 2) {
            return false;
        }

        $description = trim($dishChildNodes->item(1)->textContent);

        if (mb_strlen($description) < 10) {
            return false;
        }

        $result->setDescription($description);

        for ($i = 3; $i < $dishChildNodes->length - 1; $i++) {
            $text = trim($dishChildNodes->item($i)->textContent);
            if ($text) {
                if (mb_substr($text, 0, 1) === '€') {
                    $result->setPrice((float)mb_substr($text, 1));
                } elseif (mb_substr($text, 0, 3) === 'by ') {
                    $result->setCook(mb_substr($text, 3));
                }
            }
        }

        return $result;
    }
}
