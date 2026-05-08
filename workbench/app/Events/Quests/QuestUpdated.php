<?php

declare(strict_types=1);

namespace App\Events\Quests;

use App\Events\PartyEvent;
use App\Models\Quest;

class QuestUpdated extends PartyEvent
{
    public function __construct(string $partyId, public readonly Quest $quest)
    {
        parent::__construct($partyId);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['quest' => $this->quest->toArray()];
    }
}
