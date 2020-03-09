<?php

// src/Entity/Line.php

namespace App\Entity;

use GraphAware\Neo4j\OGM\Common\Collection;
use GraphAware\Neo4j\OGM\Annotations as OGM;

/**
 * @OGM\Node(label="Line")
 */
class Line
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
     * @OGM\Relationship(type="FINISHED_ON", direction="INCOMING", targetEntity="Game", collection=true, mappedBy="line")
     * @var Game[]|Collection
     */
    protected $games;

    public function __construct()
    {
        $this->games = new Collection();
    }

    /**
     * @return Game[]|Collection
     */
    public function getGames()
    {
        return $this->games;
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
