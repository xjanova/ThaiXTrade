<?php

namespace App\Console\Commands;

use App\Services\Stabilizer\StabilityAnnouncement;
use Illuminate\Console\Command;

/**
 * stabilizer:draft-announcement — สร้างร่างประกาศ (ไทย/อังกฤษ) ในระบบบทความ สถานะ draft.
 *
 * ไม่เผยแพร่ ไม่โพสต์ Discord — เจ้าของอ่านแล้วกดเผยแพร่เองที่ /admin/content
 *
 * Developed by Xman Studio
 */
class StabilizerDraftAnnouncement extends Command
{
    protected $signature = 'stabilizer:draft-announcement';

    protected $description = 'Create unpublished Thai/English draft articles announcing the TPIX Stability Reserve';

    public function handle(StabilityAnnouncement $announcement): int
    {
        $created = $announcement->createDrafts();

        $this->line($created === []
            ? 'drafts already exist — nothing created (delete them in /admin/content to regenerate)'
            : 'created draft(s): '.implode(', ', $created).' — review and publish manually in /admin/content');

        return self::SUCCESS;
    }
}
