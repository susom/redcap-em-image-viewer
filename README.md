# redcap-em-image-viewer
This external module permits you to preview the contents of a file_upload field.  It supports images and PDFs.
 
It should probably be called 'upload preview' but that's not for today...
 
#### Directions
 * **New:** PDF files are now supported.
 * Enable the module on your server.  If you wish to enable some logging, there is a server-setting to specify a log path.
 * Enable the module on a particular project that contains file-upload fields that you wish to use with preview
 * Use the module configuration tool to select the preview fields
 * Alternately, you can use the @IMAGEVIEW action tag to specify fields to be previewed by editing the data dictinoary directly.
 * Each field can be supplied with CSS paramaters to control formatting.  For example, on a PDF upload field you might add a parameter of ```{ "height": "400px" }```.
   * If you are supplying custom formatting using the action tag, the format is: ```@IMAGEVIEW={"height":"500px"}``` 
   * The format of the parameter string must be valid JSON (see https://jsonlint.com/ )
   * In some cases, you might not be able to override the formatting if the parent table is constraining you
   * If custom formatting is supplied both in an action tag and as part of the EM config page, the EM config page will take precedence
 * The default size is to expand to the maximum width of the current table cell.
   * For right-vertical (Default) this means upto 50% but less if the image is smaller
   * For left-vertical alignment this means the full width of the cell
   
 
#### Example
![Example Survey](docs/example.png)
 
 
*Last tested on v7.6.9*
 
 