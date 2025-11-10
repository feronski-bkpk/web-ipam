<?php
// includes/pagination.php
class Pagination {
    public $total_records;
    public $per_page;
    public $current_page;
    public $total_pages;
    public $offset;
    public $has_previous;
    public $has_next;
    
    public function __construct($total_records, $per_page = 50, $current_page = 1) {
        $this->total_records = (int)$total_records;
        $this->per_page = (int)$per_page;
        $this->current_page = max(1, (int)$current_page);
        $this->total_pages = ceil($this->total_records / $this->per_page);
        $this->offset = ($this->current_page - 1) * $this->per_page;
        $this->has_previous = $this->current_page > 1;
        $this->has_next = $this->current_page < $this->total_pages;
    }
    
    public function get_pages($max_pages = 5) {
        $start = max(1, $this->current_page - floor($max_pages / 2));
        $end = min($this->total_pages, $start + $max_pages - 1);
        
        // Adjust start if we're near the end
        $start = max(1, $end - $max_pages + 1);
        
        return range($start, $end);
    }
    
    public function get_page_url($page) {
        $query_params = $_GET;
        $query_params['page'] = $page;
        return '?' . http_build_query($query_params);
    }
    
    // Добавляем недостающие методы
    public function getLimit() {
        return "LIMIT {$this->per_page} OFFSET {$this->offset}";
    }
    
    public function getCurrentPage() {
        return $this->current_page;
    }
    
    public function getTotalPages() {
        return $this->total_pages;
    }
    
    public function render($url_template) {
        if ($this->total_pages <= 1) {
            return '';
        }
        
        $html = '<nav><ul class="pagination justify-content-center">';
        
        // Previous button
        if ($this->has_previous) {
            $prev_url = str_replace('{page}', $this->current_page - 1, $url_template);
            $html .= '<li class="page-item"><a class="page-link" href="' . $prev_url . '">&laquo; Назад</a></li>';
        }
        
        // Page numbers
        foreach ($this->get_pages() as $page) {
            $page_url = str_replace('{page}', $page, $url_template);
            $active_class = $page == $this->current_page ? ' active' : '';
            $html .= '<li class="page-item' . $active_class . '"><a class="page-link" href="' . $page_url . '">' . $page . '</a></li>';
        }
        
        // Next button
        if ($this->has_next) {
            $next_url = str_replace('{page}', $this->current_page + 1, $url_template);
            $html .= '<li class="page-item"><a class="page-link" href="' . $next_url . '">Вперед &raquo;</a></li>';
        }
        
        $html .= '</ul></nav>';
        return $html;
    }
}
?>