<?php

// src/Entity/PGN.php

namespace App\Entity;

class PGN
{
    private $PGNFilename;

    public function getPGNFilename()
    {
        return $this->PGNFilename;
    }

    public function setPGNFilename($PGNFilename)
    {
        $this->PGNFilename = $PGNFilename;
    }
}

?>

