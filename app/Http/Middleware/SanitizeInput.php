<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();
        
        $sanitized = $this->sanitizeArray($input);
        $request->merge($sanitized);

        return $next($request);
    }

    private function sanitizeArray(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    private function sanitizeString(string $value): string
    {
        // Remove potential XSS
        $value = strip_tags($value, '<p><a><strong><em><ul><ol><li>');
        
        // Remove potential SQL injection characters
        $value = str_replace(['<script', '</script>', 'javascript:', 'vbscript:', 'onload='], '', $value);
        
        // Trim whitespace
        return trim($value);
    }
}