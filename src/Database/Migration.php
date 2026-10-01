<?php
declare(strict_types=1);

namespace Whitesmoke\Database;

use Whitesmoke\Database\Schema\Schema;

interface Migration
{
    public function up(Schema $schema): void;

    public function down(Schema $schema): void;
}
