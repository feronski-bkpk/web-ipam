<?php
// includes/pagination.php
class Pagination {
    
    private $totalItems;
    private $itemsPerPage;
    private $currentPage;
    private $totalPages;
    private $maxPagesToShow = 5;
    
    public function __construct($totalItems, $itemsPerPage, $currentPage) {
        $this->totalItems = max(0, (int)$totalItems);
        $this->itemsPerPage = max(1, (int)$itemsPerPage);
        $this->currentPage = max(1, (int)$currentPage);
        $this->totalPages = $this->calculateTotalPages();
        $this->currentPage = min($this->currentPage, $this->totalPages);
    }
    
    private function calculateTotalPages() {
        if ($this->itemsPerPage == 0) return 0;
        return ceil($this->totalItems / $this->itemsPerPage);
    }
    
    public function getLimit() {
        $offset = ($this->currentPage - 1) * $this->itemsPerPage;
        return "LIMIT {$offset}, {$this->itemsPerPage}";
    }
    
    public function getCurrentPage() {
        return $this->currentPage;
    }
    
    public function getTotalPages() {
        return $this->totalPages;
    }
    
    public function render($urlTemplate = '?page={page}') {
        if ($this->totalPages <= 1) return '';
        
        $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
        
        // Previous button
        if ($this->currentPage > 1) {
            $prevUrl = str_replace('{page}', $this->currentPage - 1, $urlTemplate);
            $html .= '<li class="page-item"><a class="page-link" href="' . $prevUrl . '">&laquo; Назад</a></li>';
        }
        
        // Page numbers
        $startPage = max(1, $this->currentPage - floor($this->maxPagesToShow / 2));
        $endPage = min($this->totalPages, $startPage + $this->maxPagesToShow - 1);
        
        if ($startPage > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{page}', 1, $urlTemplate) . '">1</a></li>';
            if ($startPage > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }
        
        for ($i = $startPage; $i <= $endPage; $i++) {
            if ($i == $this->currentPage) {
                $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{page}', $i, $urlTemplate) . '">' . $i . '</a></li>';
            }
        }
        
        if ($endPage < $this->totalPages) {
            if ($endPage < $this->totalPages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{page}', $this->totalPages, $urlTemplate) . '">' . $this->totalPages . '</a></li>';
        }
        
        // Next button
        if ($this->currentPage < $this->totalPages) {
            $nextUrl = str_replace('{page}', $this->currentPage + 1, $urlTemplate);
            $html .= '<li class="page-item"><a class="page-link" href="' . $nextUrl . '">Вперед &raquo;</a></li>';
        }
        
        $html .= '</ul></nav>';
        
        return $html;
    }
}
?>