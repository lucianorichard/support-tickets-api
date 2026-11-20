<?php

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            // A user with tickets cannot be deleted: support history must survive.
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->text('description');
            $table->string('priority', 10)->default(TicketPriority::Medium->value);
            $table->string('status', 20)->default(TicketStatus::Open->value);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Match the two main queries: "my tickets" and "my assigned queue".
            $table->index(['requester_id', 'status']);
            $table->index(['assignee_id', 'status']);
            $table->index('created_at');
        });

        // Defence in depth: the database rejects values the enums do not know.
        self::check('tickets', 'tickets_priority_check', 'priority', TicketPriority::cases());
        self::check('tickets', 'tickets_status_check', 'status', TicketStatus::cases());
        self::check('users', 'users_role_check', 'role', Role::cases());
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        Schema::dropIfExists('tickets');
    }

    /** @param  list<BackedEnum>  $cases */
    private static function check(string $table, string $name, string $column, array $cases): void
    {
        $values = implode(', ', array_map(fn (BackedEnum $c) => "'{$c->value}'", $cases));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN ({$values}))");
    }
};
