<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email inquiries (crew@, patryk@, greg@) are read here now (EmailLeadIngest)
 * and become contact submissions. The ledger records every message looked
 * at, so a message is judged once whichever run sees it, and so a skipped one
 * can be re-run after a rule changes. `email_message_id` on the submission is
 * the RFC Message-ID, hashed: the same inquiry copied to two of our inboxes
 * is one lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_lead_ingests', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox', 120);
            $table->string('grant_id', 64);
            $table->string('nylas_message_id', 191)->unique();
            $table->string('rfc_message_id', 255)->nullable();
            $table->string('from_email', 255)->nullable()->index();
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->timestamp('message_at')->nullable();
            $table->string('status', 16); // lead | skipped | failed
            $table->string('skip_reason', 40)->nullable();
            $table->boolean('is_lead')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->foreignId('submission_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('subject', 255)->nullable()->after('message');
            // sha1 of the RFC Message-ID — the same identity hive keys its
            // email leads by (leads.external_id), so both sides dedupe alike.
            $table->string('email_message_id', 64)->nullable()->index()->after('attachments');
            // What the classifier pulled out of the email (project type,
            // scope, timeline, budget, partner addresses) plus the inbox it
            // arrived in — passed on to hive with the lead.
            $table->json('extracted')->nullable()->after('email_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_lead_ingests');
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropColumn(['subject', 'email_message_id', 'extracted']);
        });
    }
};
