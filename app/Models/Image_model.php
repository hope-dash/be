<?php
class Image_model extends CI_Model
{

    public function save_image($file_input_name = 'userfile', $upload_path = './uploads/', $allowed_types = 'gif|jpg|png', $max_size = 2048, $max_width = 1024, $max_height = 768)
    {
        // Load the necessary libraries
        $this->load->library('upload');
        $this->load->library('image_lib');

        // Configuration for file upload
        $config['upload_path'] = $upload_path;
        $config['allowed_types'] = $allowed_types;
        $config['max_size'] = $max_size;
        $config['max_width'] = $max_width;
        $config['max_height'] = $max_height;
        $config['file_name'] = time() . '_' . uniqid() . '_' . basename($_FILES[$file_input_name]['name']);

        // Initialize the upload library with the configuration
        $this->upload->initialize($config);

        // Check if the file was uploaded successfully
        if (!$this->upload->do_upload($file_input_name)) {
            // Handle the error
            $error = array('error' => $this->upload->display_errors());
            return $error;
        } else {
            // File uploaded successfully
            $data = array('upload_data' => $this->upload->data());
            $file_name = $data['upload_data']['file_name'];
            $file_path = $data['upload_data']['full_path'];

            // Optional: Resize the image
            $resize_config['image_library'] = 'gd2';
            $resize_config['source_image'] = $file_path;
            $resize_config['create_thumb'] = FALSE;
            $resize_config['maintain_ratio'] = TRUE;
            $resize_config['width'] = 75;
            $resize_config['height'] = 50;

            $this->image_lib->initialize($resize_config);

            if (!$this->image_lib->resize()) {
                // Handle the error
                $error = array('error' => $this->image_lib->display_errors());
                return $error;
            } else {
                // Image resized successfully
                // Return the URL of the saved image
                $base_url = base_url();
                $image_url = $base_url . str_replace('./', '', $upload_path) . $file_name;
                return $image_url;
            }
        }
    }
}
