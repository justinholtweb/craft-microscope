<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Microscope\Database\Tables;

/**
 * Microscope's two tables, exactly as Microscope 5 left them.
 *
 * A site upgrading from Craft 5 already has both (`$minVersionRequired` guarantees it), so each
 * table is skipped when it exists and its scan history carries over untouched.
 */
class Install extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(Tables::SCANS)) {
            Schema::create(Tables::SCANS, function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('status', 32)->default('pending');
                $table->string('trigger', 16)->default('manual');
                $table->text('config')->nullable();
                $table->text('environment')->nullable();
                $table->integer('score')->nullable();
                $table->text('categoryScores')->nullable();
                $table->integer('criticalCount')->default(0);
                $table->integer('warningCount')->default(0);
                $table->integer('noticeCount')->default(0);
                $table->integer('passCount')->default(0);
                $table->text('skipped')->nullable();
                $table->double('runtime')->nullable();
                $table->text('error')->nullable();
                $table->integer('creatorId')->nullable();
                $table->dateTime('dateStarted')->nullable();
                $table->dateTime('dateFinished')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                // The dashboard reads "the latest completed scan" on every load, the history chart
                // reads completed scans by date, and the scheduler reads the last scheduled one.
                $table->index(['status', 'dateCreated']);
                $table->index(['trigger', 'dateCreated']);

                $table->foreign('creatorId')->references('id')->on(Table::USERS)->nullOnDelete();
            });
        }

        if (! Schema::hasTable(Tables::FINDINGS)) {
            Schema::create(Tables::FINDINGS, function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('scanId');
                $table->string('checkHandle', 96);
                $table->string('category', 32);
                $table->string('severity', 16)->default('notice');
                $table->string('title', 255);
                $table->text('summary')->nullable();
                $table->string('observed', 255)->nullable();
                $table->string('expected', 255)->nullable();
                $table->text('impact')->nullable();
                $table->mediumText('remediation')->nullable();
                $table->string('docsUrl', 500)->nullable();
                // Template paths and long ini keys both live here.
                $table->string('target', 500)->nullable();
                $table->integer('occurrences')->default(1);
                $table->mediumText('evidence')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                $table->index(['scanId', 'severity']);
                $table->index(['scanId', 'category']);
                $table->index('checkHandle');

                $table->foreign('scanId')->references('id')->on(Tables::SCANS)->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::FINDINGS);
        Schema::dropIfExists(Tables::SCANS);
    }
}
