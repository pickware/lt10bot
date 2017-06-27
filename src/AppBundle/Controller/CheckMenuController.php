<?php
namespace AppBundle\Controller;

use AppBundle\Service\LT10Service;
use AppBundle\Service\SlackBotService;
use DateTime;
use Psr\Log\LoggerInterface as Logger;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMenuController extends Controller
{

    /**
     * Scrapes tomorrow's menu from the LT10 website (if this has not happened yet) and sends it to Slack.
     *
     * This route should be called once a day at 18:00.
     *
     * @Route("/checkmenu")
     * @param Request $request
     * @param LT10Service $lt10Service
     * @return Response
     */
    public function checkMenu(Request $request, Logger $logger, LT10Service $lt10Service, SlackBotService $slackBotService)
    {
        // Figure out which date to check for
        $dateString = $request->query->get('date') ?: null;
        $logger->info("date string passed in: ${dateString}.");
        if (!$dateString) {
            $dateString = 'today +1 Weekday'; // skip over weekends
        }
        $date = new DateTime($dateString);

        // Get the dishes for that date and announce them in Slack
        $dishes = $lt10Service->getDishesForDate($date);
        $logger->info(
            'Publishing list of dishes:',
            [
                'dishes' => $dishes
            ]
        );
        $slackBotService->publishMenu($dishes, $date);

        return new Response(Response::HTTP_NO_CONTENT);
    }
}
