<?php

declare(strict_types=1);

namespace Gcego\Data\Events;

/**
 * A record was deleted through gcego/data (dispatched after commit).
 */
final readonly class RecordDeleted extends RecordWritten {}
