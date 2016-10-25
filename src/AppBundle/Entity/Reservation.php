<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The fact that a particular Telegram user reserved a particular dish on a particular date.
 *
 * @ORM\Table(name="reservation")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\ReservationRepository")
 */
class Reservation
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="menu_date", type="string", length=255)
     */
    private $menuDate;

    /**
     * @var string
     *
     * @ORM\Column(name="user_id", type="string", length=255)
     */
    private $userId;

    /**
     * @var string
     *
     * @ORM\Column(name="user_name", type="string", length=255)
     */
    private $userName;

    /**
     * @var string
     *
     * @ORM\Column(name="dish", type="string", length=255)
     */
    private $dish;


    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set menuDate
     *
     * @param string $menuDate
     *
     * @return Reservation
     */
    public function setMenuDate($menuDate)
    {
        $this->menuDate = $menuDate;

        return $this;
    }

    /**
     * Get menuDate
     *
     * @return string
     */
    public function getMenuDate()
    {
        return $this->menuDate;
    }

    /**
     * Set userName
     *
     * @param string $userId
     *
     * @return Reservation
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Get userId
     *
     * @return string
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * Set userName
     *
     * @param string $userName
     *
     * @return Reservation
     */
    public function setUserName($userName)
    {
        $this->userName = $userName;

        return $this;
    }

    /**
     * Get userName
     *
     * @return string
     */
    public function getUserName()
    {
        return $this->userName;
    }

    /**
     * Set dish
     *
     * @param string $dish
     *
     * @return Reservation
     */
    public function setDish($dish)
    {
        $this->dish = $dish;

        return $this;
    }

    /**
     * Get dish
     *
     * @return string
     */
    public function getDish()
    {
        return $this->dish;
    }

    function __toString()
    {
        $id = $this->id;
        $menuDate = $this->menuDate;
        $userId = $this->userId;
        $dish = $this->dish;
        return "Reservation(id: ${id}, menuDate: ${menuDate}, userId: ${userId}, dish: ${dish})";
    }

}

