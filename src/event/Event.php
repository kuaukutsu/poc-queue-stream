<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

enum Event: string
{
    case TimeoutCancellation = 'cancellation by timeout';

    case MessageHandleError = 'error processing message';

    case MessageCorruptedError = 'error unmarshaling message';

    case MessageTimeoutCancellation = 'cancellation by timeout while processing message';

    case MessageAck = 'message ACK';

    case CallbackDeferred = 'callback registration';

    case CallbackDone = 'callback done';

    case RuntimeException = 'runtime exception';
}
