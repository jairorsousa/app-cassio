<?php

namespace App\Support\GmailSend;

use RuntimeException;

/** Credencial inválida / escopo / reconectar. Não retentar indefinidamente. */
class GmailSendAuthException extends RuntimeException
{
}
