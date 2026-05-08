<?php

declare(strict_types=1);

namespace App\Events\Quests;

use App\Events\PartyEvent;

class QuestRemoved extends PartyEvent
{
    public function __construct(string $partyId, public readonly string $questId)
    {
        parent::__construct($partyId);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['quest_id' => $this->questId];
    }
}
