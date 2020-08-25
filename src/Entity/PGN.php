<?php

// src/Entity/PGN.php
namespace App\Entity;

class PGN
{
    private $Filename;
    private $Text;
    private $userId;

    public function getFilename()
    {
        return $this->Filename;
    }

    public function setFilename($Filename)
    {
        $this->Filename = $Filename;
    }

    public function getText()
    {
        return $this->Text;
    }

    public function setText($Text)
    {
        $this->Text = $Text;
    }

    public function getUserId()
    {
        return $this->$userId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
    }
}
?>
