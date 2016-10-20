<?php

namespace AppBundle\Scraper;

use Exception;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class LT10ServiceException extends Exception {}

/**
 * Class LT10Service
 *
 * Handles communication with the functionality of http://kantine.lt10.de/menus.
 *
 * @package AppBundle\Scraper
 */
class LT10Service
{
    const ENDPOINT = 'http://kantine.lt10.de/menu';
    const REQUESTED_DATE = 'morgen';
    private $client;
    private $userName;
    private $password;

    function __construct($logger)
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
    }

    /**
     * Update the total number of reservations for a single dish
     * @param $date the date of the reservation
     * @param $dish the dish for which to change the number of reservations
     * @param $numReservations how many reservations to make
     */
    public function updateDishReservations($date, $dish, $numReservations)
    {
        if ($numReservations >= 5) {
            throw new LT10ServiceException("Maximum number of reservations exceeded.");
        }
        $this->client = new Client();
        $this->login();
        $submitButton = $this->crawler->selectButton('submit');
        $reservationForm = $submitButton->form();
        $reservationForm->setValues([
            "${date}_${dish}_count" => $numReservations
        ]);
        $resultCrawler = $this->client->submit($reservationForm);
        $this->logger->info("Updated reservations on ${date}: dish ${dish} is reserved ${numReservations} times.");
    }

    /**
     * Scrape tomorrow's dishes
     * @return array
     */
    public function getDishesForTomorrow()
    {
        $this->client = new Client();
        $this->login();
        return $this->parseDay();
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
            $loginForm->setValues([
                'u' => $this->userName,
                'p' => $this->password
            ]);
            $this->crawler = $this->client->submit($loginForm);
        }
    }

    /**
     * Finds and parses the correct day (i.e. tomorrow)
     * @return array
     */
    private function parseDay()
    {
        return $this->crawler
            ->filter('.day')
            ->reduce(function (Crawler $day) {
                $date = $day->filter('p.date')->text();
                return $date === static::REQUESTED_DATE;
            })
            ->filter('.menu')
            ->each(function(Crawler $dish) {
                return $this->parseDish($dish);
            });
    }

    /**
     * Parse description, cost, cooks and additional tags from a dish (i.e. div.menu html element)
     * @param Crawler $dish
     * @return array|bool
     */
    private function parseDish(Crawler $dish)
    {
        $dishChildNodes = $dish->getNode(0)->childNodes;
        if ($dishChildNodes->length <= 2) {
            return false;
        }

        $result = [
            'description' => trim($dishChildNodes->item(1)->textContent),
            'tags' => []
        ];

        for ($i = 3; $i < $dishChildNodes->length - 1; $i++) {
            $text = trim($dishChildNodes->item($i)->textContent);
            if ($text) {
                if (mb_substr($text, 0, 1) === '€') {
                    $result['price'] = (float)mb_substr($text, 1);
                } elseif (mb_substr($text, 0, 3) === 'by ') {
                    $result['cook'] = mb_substr($text, 3);
                } else {
                    $result['tags'][] = trim($dishChildNodes->item($i)->textContent);
                }
            }
        }
        return $result;
    }
}