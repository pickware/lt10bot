<?php
namespace AppBundle\Service;

use AppBundle\Entity\Dish;
use AppBundle\Entity\Reservation;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Psr\Log\LoggerInterface as Logger;

class ReservationService
{
    const MAX_RESERVATION_COUNT = 5;

    /** @var LT10Service */
    private $lt10Service;

    /** @var EntityManager */
    private $entityManager;

    public function __construct(Logger $logger, LT10Service $lt10Service, EntityManager $entityManager)
    {
        $this->lt10Service = $lt10Service;
        $this->entityManager = $entityManager;
    }

    /**
     * @param Dish $dish
     * @param int $desiredAmount
     * @param string $userId
     * @param string $userName
     */
    public function makeReservation(Dish $dish, int $desiredAmount, string $userId, string $userName)
    {
        $this->entityManager->refresh($dish);
        
        $otherUserReservationAmount = 0;
        $existingReservation = null;
        /** @var Reservation $reservation */
        foreach ($dish->getReservations() as $reservation) {
            if ($reservation->getUserId() === $userId) {
                $existingReservation = $reservation;
            } else {
                $otherUserReservationAmount += $reservation->getAmount();
            }
        }

        if ($otherUserReservationAmount + $desiredAmount > self::MAX_RESERVATION_COUNT) {
            throw new InvalidArgumentException('So viele Gerichte kann ich leider nicht mehr reservieren :/');
        }
        

        $numReservations = $otherUserReservationAmount;

        if ($desiredAmount > 0 && $existingReservation === null) {
            // New reservation
            $reservation = new Reservation();
            $reservation->setDish($dish);
            $reservation->setUserId($userId);
            $reservation->setUserName($userName);
            $reservation->setAmount($desiredAmount);
            $numReservations += $desiredAmount;
            $this->lt10Service->updateDishReservations($dish, $numReservations);
            $this->entityManager->persist($reservation);
            $this->entityManager->flush($reservation);
        } elseif ($desiredAmount > 0 && $existingReservation !== null && $desiredAmount !== $existingReservation->getAmount()) {
            $reservation = $existingReservation;
            $reservation->setAmount($desiredAmount);
            $numReservations += $desiredAmount;
            $this->lt10Service->updateDishReservations($dish, $numReservations);
            $this->entityManager->flush($reservation);
        } elseif ($existingReservation && $desiredAmount === 0) {
            $this->lt10Service->updateDishReservations($dish, $numReservations);
            $this->entityManager->remove($existingReservation);
            $this->entityManager->flush($existingReservation);
        }

        $this->entityManager->refresh($dish);
    }

}
