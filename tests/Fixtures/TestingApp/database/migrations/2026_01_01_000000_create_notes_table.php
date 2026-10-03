<?php
declare(strict_types=1);

use Whitesmoke\Database\Migration;
use Whitesmoke\Database\Schema\Blueprint;
use Whitesmoke\Database\Schema\Schema;

return new class implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('body', 200);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('notes');
    }
};
