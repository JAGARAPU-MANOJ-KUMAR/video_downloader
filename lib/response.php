<?php
class Response {
    public static function json($success, $data_or_error, $http_status = 200) {
        http_response_code($success ? 200 : $http_status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *'); 
        
        $response = ['success' => $success];
        if ($success) {
            $response['data'] = $data_or_error;
        } else {
            $response['error'] = $data_or_error;
        }
        
        echo json_encode($response);
        exit;
    }

    public static function error($code, $message, $http_status = 400) {
        self::json(false, ['code' => $code, 'message' => $message], $http_status);
    }
}
