<?php

// src/Entity/Game.php

namespace App\Entity;

use GraphAware\Neo4j\OGM\Annotations as OGM;

/**
 * @OGM\Node(label="Game")
 */
class Game
{
    /**
     * @OGM\GraphId()
     * @var int
     */
    protected $id;

    /**
     * @OGM\Property(type="string")
     * @var string
     */
    protected $hash;

    /**
     * @OGM\Relationship(type="FINISHED_ON", direction="OUTGOING", targetEntity="Line", collection=false, mappedBy="games")
     * @var Line
     */
    protected $line;

    public function __construct()
    {
        $this->line = new Line();
    }

    /**
     * @return Line
     */
    public function getLine()
    {
        return $this->line;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }
}

?>

