<?php

// src/Message/InputOutputOperation.php

namespace App\Message;

class InputOutputOperation
{
    private $operation;
    private $analysis_id;
    private $game_id;
    private $user_id;

    public function __construct(string $operation, array $params)
    {
        $this->operation = $operation;

        if (array_key_exists( 'analysis_id', $params) && is_numeric( $params['analysis_id']))
          $this->analysis_id = $params['analysis_id'];
        else
          $this->analysis_id  = -1;

        if (array_key_exists( 'game_id', $params) && is_numeric( $params['game_id']))
          $this->game_id = $params['game_id'];
        else
          $this->game_id  = -1;

        if (array_key_exists( 'user_id', $params) && is_numeric( $params['user_id']))
          $this->user_id = $params['user_id'];
        else
          $this->user_id = $_ENV['SYSTEM_WEB_USER_ID'];
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getAnalysisId(): int
    {
        return $this->analysis_id;
    }

    public function getGameId(): int
    {
        return $this->game_id;
    }

    public function getUserId(): int
    {
        return $this->user_id;
    }
}
?>
