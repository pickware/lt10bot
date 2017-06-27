<?php
namespace AppBundle\Controller;

use AppBundle\Entity\Dish;
use AppBundle\Service\SlackBotService;
use DateTime;
use Doctrine\ORM\EntityManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

class LunchReminderController extends Controller
{
    /**
     * Reminds everybody who reserved lunch to actually go and eat it.
     *
     * @Route("/lunchreminder")
     */
    public function lunchReminderAction(EntityManager $entityManager, SlackBotService $slackBotService)
    {
        $dishes = $entityManager->getRepository(Dish::class)->findBy(
            [
                'date' => new DateTime()
            ]
        );
        foreach ($dishes as $dish) {
            foreach ($dish->getReservations() as $reservation) {
                $slackBotService->sendLunchReminder($reservation);
            }
        }

        return new Response(Response::HTTP_NO_CONTENT);
    }
}
