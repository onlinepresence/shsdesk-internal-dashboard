<?php

namespace App\Console\Commands;

use App\Models\Licence;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('licence:verification-key')]
#[Description('Print the Ed25519 verification key for air-gap licence files')]
class LicenceVerificationKey extends Command
{
    /**
     * Print the hex verification key matching LICENCE_SIGNING_KEY so
     * it can be published to the FlowEdu docs. The seed itself is
     * never displayed.
     */
    public function handle(): int
    {
        try {
            $this->line(Licence::verificationKeyHex());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
