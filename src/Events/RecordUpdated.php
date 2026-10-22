<?php

declare(strict_types=1);

namespace Gcego\Data\Events;

/**
 * A record was updated through gcego/data (dispatched after commit).
 */
final readonly class RecordUpdated extends RecordWritten {}
