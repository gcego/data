<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

/**
 * Capability marker: the provider eager loads With and Includable relations and
 * understands relation paths ("topics.name") in filters and search.
 */
interface SupportsRelationships {}
