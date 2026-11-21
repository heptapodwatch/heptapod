<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Symfony\Component\Mailer\SentMessage;

/** A message the log transport accepted: written to the log, received by nobody. */
final class LoggedSentMessage extends SentMessage {}
