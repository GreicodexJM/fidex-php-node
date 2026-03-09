<?php

declare(strict_types=1);

namespace FideX\Core\Domain;

/**
 * Message Status Enum
 *
 * Tracks the lifecycle of a FideX message through the system.
 *
 * Outbound flow:
 *   QUEUED → SENT → ACKNOWLEDGED | FAILED | RETRY
 *
 * Inbound flow:
 *   RECEIVED → DECRYPTED → DELIVERED
 */
enum MessageStatus: string
{
    case PENDING      = 'PENDING';       // Initial state
    case QUEUED       = 'QUEUED';        // Outbound: encrypted + queued for HTTP delivery
    case SENT         = 'SENT';          // Outbound: HTTP 202 received from partner
    case ACKNOWLEDGED = 'ACKNOWLEDGED';  // Outbound: J-MDN received with DELIVERED status
    case RECEIVED     = 'RECEIVED';      // Inbound: envelope accepted, queued for decryption
    case DECRYPTED    = 'DECRYPTED';     // Inbound: JWE decrypted, J-MDN sent
    case DELIVERED    = 'DELIVERED';     // Inbound: full processing complete
    case FAILED       = 'FAILED';        // Any direction: terminal failure
    case RETRY        = 'RETRY';         // Outbound: queued for retry after failure
}
