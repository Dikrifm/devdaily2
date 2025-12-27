<?php

namespace App\DTOs\Responses;

use DateTimeImmutable;
use InvalidArgumentException;

class DashboardStatisticsResponse
{
    // Core statistics
    private array $summaryCards = [];
    private array $trendMetrics = [];
    private array $charts = [];
    private array $recentActivities = [];
    private array $topLists = [];
    private array $performanceMetrics = [];
    private array $systemHealth = [];
    
    // Metadata
    private string $period;
    private string $generatedAt;
    private ?string $timezone = 'UTC';
    private array $widgetConfig = [];
    private array $dataSources = [];
    
    // Configuration
    private bool $includeCharts = true;
    private bool $includeActivities = true;
    private bool $includeHealth = false;
    private int $activitiesLimit = 10;
    private int $topListsLimit = 5;
    
    private function __construct()
    {
        $this->generatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
    
    public static function create(array $config = []): self
    {
        $response = new self();
        $response->applyConfiguration($config);
        return $response;
    }
    
    public static function fromArray(array $data): self
    {
        $response = new self();
        
        // Set basic properties
        if (isset($data['period'])) {
            $response->setPeriod($data['period']);
        }
        
        if (isset($data['summary_cards']) && is_array($data['summary_cards'])) {
            $response->summaryCards = $data['summary_cards'];
        }
        
        if (isset($data['trend_metrics']) && is_array($data['trend_metrics'])) {
            $response->trendMetrics = $data['trend_metrics'];
        }
        
        if (isset($data['charts']) && is_array($data['charts'])) {
            $response->charts = $data['charts'];
        }
        
        if (isset($data['recent_activities']) && is_array($data['recent_activities'])) {
            $response->recentActivities = $data['recent_activities'];
        }
        
        if (isset($data['top_lists']) && is_array($data['top_lists'])) {
            $response->topLists = $data['top_lists'];
        }
        
        if (isset($data['performance_metrics']) && is_array($data['performance_metrics'])) {
            $response->performanceMetrics = $data['performance_metrics'];
        }
        
        if (isset($data['system_health']) && is_array($data['system_health'])) {
            $response->systemHealth = $data['system_health'];
        }
        
        if (isset($data['widget_config']) && is_array($data['widget_config'])) {
            $response->widgetConfig = $data['widget_config'];
        }
        
        if (isset($data['data_sources']) && is_array($data['data_sources'])) {
            $response->dataSources = $data['data_sources'];
        }
        
        return $response;
    }
    
    private function applyConfiguration(array $config): void
    {
        $this->period = $config['period'] ?? 'today';
        $this->timezone = $config['timezone'] ?? 'UTC';
        $this->includeCharts = $config['include_charts'] ?? true;
        $this->includeActivities = $config['include_activities'] ?? true;
        $this->includeHealth = $config['include_health'] ?? false;
        $this->activitiesLimit = $config['activities_limit'] ?? 10;
        $this->topListsLimit = $config['top_lists_limit'] ?? 5;
        $this->widgetConfig = $config['widget_config'] ?? [];
    }
    
    // Summary Cards Methods
    public function addSummaryCard(string $key, string $title, $value, array $options = []): self
    {
        $card = [
            'key' => $key,
            'title' => $title,
            'value' => $value,
            'icon' => $options['icon'] ?? null,
            'color' => $options['color'] ?? 'primary',
            'trend' => $options['trend'] ?? null,
            'trend_direction' => $options['trend_direction'] ?? null, // 'up', 'down', 'neutral'
            'format' => $options['format'] ?? 'number', // 'number', 'currency', 'percentage', 'duration'
            'currency' => $options['currency'] ?? 'IDR',
            'prefix' => $options['prefix'] ?? '',
            'suffix' => $options['suffix'] ?? '',
            'link' => $options['link'] ?? null,
            'description' => $options['description'] ?? null,
            'updated_at' => $options['updated_at'] ?? $this->generatedAt,
        ];
        
        $this->summaryCards[$key] = $card;
        return $this;
    }
    
    public function updateSummaryCard(string $key, array $updates): self
    {
        if (isset($this->summaryCards[$key])) {
            $this->summaryCards[$key] = array_merge($this->summaryCards[$key], $updates);
        }
        return $this;
    }
    
    // Trend Metrics Methods
    public function addTrendMetric(string $key, string $title, array $dataPoints, array $options = []): self
    {
        $metric = [
            'key' => $key,
            'title' => $title,
            'data_points' => $dataPoints,
            'metric_type' => $options['metric_type'] ?? 'count', // 'count', 'revenue', 'conversion', 'engagement'
            'chart_type' => $options['chart_type'] ?? 'line', // 'line', 'area', 'bar'
            'current_value' => $options['current_value'] ?? null,
            'previous_value' => $options['previous_value'] ?? null,
            'change_percentage' => $options['change_percentage'] ?? null,
            'change_direction' => $options['change_direction'] ?? null, // 'positive', 'negative', 'neutral'
            'period_comparison' => $options['period_comparison'] ?? null, // 'day', 'week', 'month', 'year'
            'time_range' => $options['time_range'] ?? '7d',
            'format' => $options['format'] ?? 'number',
        ];
        
        $this->trendMetrics[$key] = $metric;
        return $this;
    }
    
    // Charts Methods
    public function addChart(string $key, string $title, string $type, array $data, array $options = []): self
    {
        $chart = [
            'key' => $key,
            'title' => $title,
            'type' => $type, // 'line', 'bar', 'pie', 'doughnut', 'radar', 'polarArea'
            'data' => $data,
            'options' => $options['chart_options'] ?? [],
            'height' => $options['height'] ?? 300,
            'width' => $options['width'] ?? '100%',
            'legend' => $options['legend'] ?? true,
            'tooltips' => $options['tooltips'] ?? true,
            'responsive' => $options['responsive'] ?? true,
            'time_range' => $options['time_range'] ?? $this->period,
            'data_source' => $options['data_source'] ?? null,
            'refresh_interval' => $options['refresh_interval'] ?? null,
        ];
        
        $this->charts[$key] = $chart;
        return $this;
    }
    
    public function addLineChart(string $key, string $title, array $datasets, array $labels, array $options = []): self
    {
        $data = [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
        
        $chartOptions = array_merge([
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ], $options['chart_options'] ?? []);
        
        return $this->addChart($key, $title, 'line', $data, array_merge($options, [
            'chart_options' => $chartOptions,
        ]));
    }
    
    public function addBarChart(string $key, string $title, array $datasets, array $labels, array $options = []): self
    {
        $data = [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
        
        $chartOptions = array_merge([
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ], $options['chart_options'] ?? []);
        
        return $this->addChart($key, $title, 'bar', $data, array_merge($options, [
            'chart_options' => $chartOptions,
        ]));
    }
    
    public function addPieChart(string $key, string $title, array $data, array $labels, array $options = []): self
    {
        $chartData = [
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $options['background_colors'] ?? $this->generateColors(count($data)),
            ]],
        ];
        
        return $this->addChart($key, $title, 'pie', $chartData, $options);
    }
    
    // Recent Activities Methods
    public function addRecentActivity(array $activity): self
    {
        $this->recentActivities[] = $activity;
        
        // Limit the number of activities
        if (count($this->recentActivities) > $this->activitiesLimit) {
            $this->recentActivities = array_slice($this->recentActivities, -$this->activitiesLimit);
        }
        
        return $this;
    }
    
    public function setRecentActivities(array $activities): self
    {
        $this->recentActivities = array_slice($activities, 0, $this->activitiesLimit);
        return $this;
    }
    
    // Top Lists Methods
    public function addTopList(string $key, string $title, array $items, array $options = []): self
    {
        $list = [
            'key' => $key,
            'title' => $title,
            'items' => array_slice($items, 0, $this->topListsLimit),
            'item_type' => $options['item_type'] ?? 'default', // 'product', 'category', 'link', 'marketplace'
            'sort_by' => $options['sort_by'] ?? 'value',
            'sort_order' => $options['sort_order'] ?? 'desc',
            'value_label' => $options['value_label'] ?? 'Value',
            'format' => $options['format'] ?? 'number',
            'show_rank' => $options['show_rank'] ?? true,
            'show_change' => $options['show_change'] ?? false,
            'link_template' => $options['link_template'] ?? null,
        ];
        
        $this->topLists[$key] = $list;
        return $this;
    }
    
    // Performance Metrics Methods
    public function addPerformanceMetric(string $key, string $title, $value, $target, array $options = []): self
    {
        $percentage = $target > 0 ? ($value / $target) * 100 : 0;
        
        $metric = [
            'key' => $key,
            'title' => $title,
            'value' => $value,
            'target' => $target,
            'percentage' => $percentage,
            'status' => $this->calculatePerformanceStatus($percentage, $options['thresholds'] ?? [70, 90]),
            'unit' => $options['unit'] ?? '',
            'format' => $options['format'] ?? 'number',
            'description' => $options['description'] ?? null,
            'trend' => $options['trend'] ?? null,
        ];
        
        $this->performanceMetrics[$key] = $metric;
        return $this;
    }
    
    // System Health Methods
    public function addSystemHealthCheck(string $component, string $status, array $details = []): self
    {
        $check = [
            'component' => $component,
            'status' => $status, // 'healthy', 'warning', 'error', 'maintenance'
            'timestamp' => $this->generatedAt,
            'details' => $details,
            'icon' => $this->getHealthIcon($status),
            'color' => $this->getHealthColor($status),
        ];
        
        $this->systemHealth[$component] = $check;
        return $this;
    }
    
    // Data Sources Tracking
    public function addDataSource(string $source, array $metadata = []): self
    {
        $this->dataSources[$source] = array_merge([
            'source' => $source,
            'retrieved_at' => $this->generatedAt,
            'cache_status' => 'hit', // or 'miss'
        ], $metadata);
        
        return $this;
    }
    
    // Helper Methods
    private function generateColors(int $count): array
    {
        $colors = [
            '#3b82f6', // blue-500
            '#10b981', // emerald-500
            '#f59e0b', // amber-500
            '#ef4444', // red-500
            '#8b5cf6', // violet-500
            '#ec4899', // pink-500
            '#14b8a6', // teal-500
            '#f97316', // orange-500
            '#84cc16', // lime-500
            '#06b6d4', // cyan-500
        ];
        
        if ($count <= count($colors)) {
            return array_slice($colors, 0, $count);
        }
        
        // Generate additional colors if needed
        $generated = $colors;
        for ($i = count($colors); $i < $count; $i++) {
            $generated[] = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
        }
        
        return $generated;
    }
    
    private function calculatePerformanceStatus(float $percentage, array $thresholds): string
    {
        if (count($thresholds) >= 2) {
            [$warning, $good] = $thresholds;
            
            if ($percentage >= $good) {
                return 'excellent';
            } elseif ($percentage >= $warning) {
                return 'good';
            } else {
                return 'needs_improvement';
            }
        }
        
        // Default thresholds
        if ($percentage >= 90) {
            return 'excellent';
        } elseif ($percentage >= 70) {
            return 'good';
        } else {
            return 'needs_improvement';
        }
    }
    
    private function getHealthIcon(string $status): string
    {
        $icons = [
            'healthy' => 'fas fa-check-circle',
            'warning' => 'fas fa-exclamation-triangle',
            'error' => 'fas fa-times-circle',
            'maintenance' => 'fas fa-tools',
        ];
        
        return $icons[$status] ?? 'fas fa-question-circle';
    }
    
    private function getHealthColor(string $status): string
    {
        $colors = [
            'healthy' => 'text-green-500',
            'warning' => 'text-yellow-500',
            'error' => 'text-red-500',
            'maintenance' => 'text-blue-500',
        ];
        
        return $colors[$status] ?? 'text-gray-500';
    }
    
    // Getters
    public function getSummaryCards(): array { return $this->summaryCards; }
    public function getTrendMetrics(): array { return $this->trendMetrics; }
    public function getCharts(): array { return $this->charts; }
    public function getRecentActivities(): array { return $this->recentActivities; }
    public function getTopLists(): array { return $this->topLists; }
    public function getPerformanceMetrics(): array { return $this->performanceMetrics; }
    public function getSystemHealth(): array { return $this->systemHealth; }
    public function getPeriod(): string { return $this->period; }
    public function getGeneratedAt(): string { return $this->generatedAt; }
    public function getTimezone(): ?string { return $this->timezone; }
    public function getWidgetConfig(): array { return $this->widgetConfig; }
    public function getDataSources(): array { return $this->dataSources; }
    
    public function setPeriod(string $period): self
    {
        $this->period = $period;
        return $this;
    }
    
    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }
    
    // Output Methods
    public function toDashboardArray(): array
    {
        $dashboard = [
            'period' => $this->period,
            'generated_at' => $this->generatedAt,
            'timezone' => $this->timezone,
            'summary_cards' => $this->summaryCards,
            'trend_metrics' => $this->trendMetrics,
        ];
        
        if ($this->includeCharts && !empty($this->charts)) {
            $dashboard['charts'] = $this->charts;
        }
        
        if ($this->includeActivities && !empty($this->recentActivities)) {
            $dashboard['recent_activities'] = $this->recentActivities;
        }
        
        if (!empty($this->topLists)) {
            $dashboard['top_lists'] = $this->topLists;
        }
        
        if (!empty($this->performanceMetrics)) {
            $dashboard['performance_metrics'] = $this->performanceMetrics;
        }
        
        if ($this->includeHealth && !empty($this->systemHealth)) {
            $dashboard['system_health'] = $this->systemHealth;
        }
        
        if (!empty($this->widgetConfig)) {
            $dashboard['widget_config'] = $this->widgetConfig;
        }
        
        if (!empty($this->dataSources)) {
            $dashboard['data_sources'] = $this->dataSources;
        }
        
        return $dashboard;
    }
    
    public function toWidgetArray(): array
    {
        // Group by widget type for frontend rendering
        return [
            'summary_cards' => array_values($this->summaryCards),
            'trend_metrics' => array_values($this->trendMetrics),
            'charts' => array_values($this->charts),
            'recent_activities' => $this->recentActivities,
            'top_lists' => array_values($this->topLists),
            'performance_metrics' => array_values($this->performanceMetrics),
            'system_health' => array_values($this->systemHealth),
            'metadata' => [
                'period' => $this->period,
                'generated_at' => $this->generatedAt,
                'timezone' => $this->timezone,
            ],
        ];
    }
    
    public function toArray(): array
    {
        return $this->toDashboardArray();
    }
    
    public function getCacheKey(string $prefix = 'dashboard_stats_'): string
    {
        $parts = [
            $prefix,
            $this->period,
            $this->includeCharts ? 'with_charts' : 'no_charts',
            $this->includeActivities ? 'with_activities' : 'no_activities',
            $this->includeHealth ? 'with_health' : 'no_health',
            substr(md5($this->generatedAt), 0, 8),
        ];
        
        return implode('_', $parts);
    }
    
    public function toJson(bool $pretty = false): string
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }
        
        return json_encode($this->toArray(), $options);
    }
    
    // Static factory methods for common dashboard types
    public static function createAdminDashboard(array $data, array $config = []): self
    {
        $response = self::create(array_merge([
            'period' => 'today',
            'include_charts' => true,
            'include_activities' => true,
            'activities_limit' => 10,
        ], $config));
        
        // Add summary cards
        if (isset($data['summary_cards'])) {
            foreach ($data['summary_cards'] as $key => $card) {
                $response->addSummaryCard($key, $card['title'], $card['value'], $card);
            }
        }
        
        // Add trend metrics
        if (isset($data['trend_metrics'])) {
            foreach ($data['trend_metrics'] as $key => $metric) {
                $response->addTrendMetric($key, $metric['title'], $metric['data_points'], $metric);
            }
        }
        
        // Add charts
        if (isset($data['charts'])) {
            foreach ($data['charts'] as $key => $chart) {
                $response->addChart($key, $chart['title'], $chart['type'], $chart['data'], $chart);
            }
        }
        
        // Add recent activities
        if (isset($data['recent_activities'])) {
            $response->setRecentActivities($data['recent_activities']);
        }
        
        return $response;
    }
    
    public static function createProductDashboard(array $data, array $config = []): self
    {
        $response = self::create(array_merge([
            'period' => '7d',
            'include_charts' => true,
            'include_activities' => false,
        ], $config));
        
        // Add product-specific summary cards
        $defaultCards = [
            'total_products' => ['title' => 'Total Products', 'icon' => 'fas fa-box', 'color' => 'blue'],
            'published_products' => ['title' => 'Published', 'icon' => 'fas fa-check-circle', 'color' => 'green'],
            'draft_products' => ['title' => 'Drafts', 'icon' => 'fas fa-edit', 'color' => 'yellow'],
            'products_with_links' => ['title' => 'With Links', 'icon' => 'fas fa-link', 'color' => 'purple'],
        ];
        
        foreach ($defaultCards as $key => $card) {
            if (isset($data[$key])) {
                $response->addSummaryCard($key, $card['title'], $data[$key], $card);
            }
        }
        
        // Add view trend chart
        if (isset($data['view_trend']) && isset($data['view_labels'])) {
            $response->addLineChart(
                'product_views',
                'Product Views Trend',
                $data['view_trend'],
                $data['view_labels'],
                ['time_range' => '7d']
            );
        }
        
        // Add top performing products
        if (isset($data['top_products'])) {
            $response->addTopList(
                'top_products',
                'Top Performing Products',
                $data['top_products'],
                ['item_type' => 'product', 'value_label' => 'Views']
            );
        }
        
        return $response;
    }
}