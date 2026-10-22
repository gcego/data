<?php

declare(strict_types=1);

namespace Gcego\Data\Events;

/**
 * A record was created through gcego/data (dispatched after commit).
 */
final readonly class RecordCreated extends RecordWritten {}
