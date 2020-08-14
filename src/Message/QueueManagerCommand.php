<?php

// src/Message/QueueManagerCommand.php

namespace App\Message;

class QueueManagerCommand
{
    private $command;
    private $analysis_id;
    private $game_id;
    private $depth;
    private $side_label;
    private $status;
    private $user_id;

    public function __construct(string $command, array $params)
    {
        $this->command = $command;

        if (array_key_exists( 'analysis_id', $params))
          $this->analysis_id = $params['analysis_id'];
        else
          $this->analysis_id  = -1;

        if (array_key_exists( 'game_id', $params))
          $this->game_id = $params['game_id'];
        else
          $this->game_id  = -1;

        if (array_key_exists( 'depth', $params))
          $this->depth = $params['depth'];
        else
          $this->depth  = $_ENV['FAST_ANALYSIS_DEPTH'];

        if (array_key_exists( 'side_label', $params))
          $this->side_label = $params['side_label'];
        else
          $this->side_label = ':WhiteSide:BlackSide';

        if (array_key_exists( 'status', $params))
          $this->status = $params['status'];
        else
          $this->status = 'Pending';

        if (array_key_exists( 'user_id', $params))
          $this->user_id = $params['user_id'];
        else
          $this->user_id = $_ENV['SYSTEM_WEB_USER_ID'];
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getAnalysisId(): int
    {
        return $this->analysis_id;
    }

    public function getGameId(): int
    {
        return $this->game_id;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getSideLabel(): string
    {
      return $this->side_label;
    }

    public function getStatus(): string
    {
      return $this->side_label;
    }

    public function getUserId(): int
    {
        return $this->user_id;
    }
}
?>
