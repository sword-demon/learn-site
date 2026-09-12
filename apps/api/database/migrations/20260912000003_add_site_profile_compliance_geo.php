<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSiteProfileComplianceGeo extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('site_profile');
        if (!$table->hasColumn('icp_number')) {
            $table->addColumn('icp_number', 'string', ['limit' => 80, 'null' => false, 'default' => '']);
        }
        if (!$table->hasColumn('geo_content')) {
            $table->addColumn('geo_content', 'text', ['null' => false]);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('site_profile');
        foreach (['geo_content', 'icp_number'] as $column) {
            if ($table->hasColumn($column)) $table->removeColumn($column);
        }
        $table->update();
    }
}
