<?php
/**
 *  Randomised Programming Quiz System - A quiz system that develops random programming questions from defined templates
 *  Copyright (C) 2010-2012 Ben Evans <ben@nebev.net>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 **/


class Model_Shell_Compiler{
	
	const NIX_OSES = "linux,darwin,unix,bsd";
	
	public static function compileAndReturn($vFilePrefix, $mSource){
		//return self::callCompileService($mSource);
		
		$mTempFolder = Model_Shell_Compiler::os_slash(APPLICATION_PATH . "/../tmp");
		//return false;
		if( strtolower(COMPILER_TYPE) == "c++" ) {
			return Model_Shell_Compiler::cpp_compile_and_return( $mTempFolder, $vFilePrefix, $mSource );
		}elseif( strtolower(COMPILER_TYPE) == "java" ) {
			return Model_Shell_Compiler::java_compile_and_return( $mTempFolder, $vFilePrefix, $mSource );
		}
	
	}
	
	
	static function callCompileService($sourceCode){
		try {
			My_Logger::log(__METHOD__ . " " . $sourceCode);
			$url = "http://localhost:8086/CompilerService/services/CompilerService?wsdl";
			$client = new Zend_Soap_Client($url
					, array('encoding' => 'UTF-8'
					)
			);
			
			$arg = new stdClass();
			$arg->sourceCode = "public " . $sourceCode;
			//$arg->b = 11;
			
			$res = $client->compile($arg);
			My_Logger::log(__METHOD__ . " " . print_r($res, true));
			//print_r($res);
			return $res->return;
		} catch (Exception $e) {
			$msg = $e->getMessage();
			My_Logger::log(__METHOD__ . " service returned error: " . $msg);
			$cex = new CompilerException("Compiler service found errors: ");
			$cex->compiler_output = $msg;
			throw $cex;
		}
		
	}
	
	
	/**
	 * Java-Specific Compilation
	 *
	 * @param string $mTempFolder 
	 * @param string $vFilePrefix 
	 * @param string $mSource 
	 * @return string
	 * @author Ben Evans
	 */
	private static function java_compile_and_return($mTempFolder, $vFilePrefix, $mSource) {
		
		Model_Shell_Debug::getInstance()->log("Entering Java Compilation");
		Model_Shell_Debug::getInstance()->log("Temporary Folder Given: $mTempFolder . File Prefix: $vFilePrefix");
		Model_Shell_Debug::getInstance()->log("Source passed was:\n$mSource");
		
		//We are probably better off using a strategy pattern - Ivan
		if(in_array(strtolower(PHP_OS), explode(",", self::NIX_OSES) )){
			return self::java_linux($mTempFolder, $vFilePrefix, $mSource);
		}else{
		    //We're running Windows (probably)
			return self::java_win($mTempFolder, $vFilePrefix, $mSource);
		}
		
	}
	
	static protected function java_linux($mTempFolder, $vFilePrefix, $mSource) {
		// Clean up any old existing files
		if (file_exists(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".java"))) {
			Model_Shell_Debug::getInstance()->log("Existing file exists. Removing");
			unlink(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".java"));
		}
		if (file_exists(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix))) {
			Model_Shell_Debug::getInstance()->log("Existing folder exists. Removing");
			unlink(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix));
		}
		
		$mTempFolder = self::os_slash($mTempFolder);
		
		// Output the program that's been generated into a File.
		$fh = fopen(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".java"), 'w');
		fwrite($fh, $mSource);
		fclose($fh);
		
		if (!is_writable($mTempFolder)) {
			throw new Exception("Cannot write to $mTempFolder Please ensure permissions are correctly set");
		}
		
		// Java sometimes generates more than one class... so we put the compiled things in a directory
		mkdir($mTempFolder . "/" . $vFilePrefix);
		
		$error_file = $mTempFolder . '/' . $vFilePrefix . '.error.txt'; // Capture error messages here - Ivan
		// Run the program that we outputted to a file into a fully functioning Executable
		$toExec = "javac \"" . $mTempFolder . "/" . $vFilePrefix . ".java\" -d \"" . $mTempFolder . "/" . $vFilePrefix . "\"" 
		    	. ' 2> "' . $error_file . '"' //capture errors
		    	;
		Model_Shell_Debug::getInstance()->log("Attempted to execute: $toExec");
		$execResult = exec($toExec);
		Model_Shell_Debug::getInstance()->log("Execution result was: " . $execResult);
		
		self::proccessErrorFile($error_file); // Ivan
		                                      
		// OK Now we need to see what classes have been generated in this directory, and then call that when executing
		$directory_contents = scandir($mTempFolder . "/" . $vFilePrefix);
		if (sizeof($directory_contents) < 3) {
			throw new Exception("When Trying to generate a new randomised question, Compilation Failed. Reason: " . $execResult);
		}
		
		// At this point, I'm assuming we have only 1 main class
		$program_to_run = null;
		foreach ( $directory_contents as $dc ) {
			if (strlen($dc) > 5) {
				$program_to_run = str_replace(".class", "", $dc);
			}
		}
		
		if (!is_null($program_to_run)) {
			
			// Most linuxes and Unixes come with the GNU timeout program. However, OSX doesn't
			if (in_array(strtolower(PHP_OS), array ('darwin'))) {
				$toExec = realpath(APPLICATION_PATH . "/../resources/osx_timeout.sh") . " 5 java -cp \"" . $mTempFolder . "/" . $vFilePrefix . "\" " . $program_to_run . " > \"" . $mTempFolder . "/" . $vFilePrefix . ".txt\"";
			} else {
				// For now we will only support our target platform (Ubuntu) - Ivan
				$toExec = "timeout 5 java -cp \"" . $mTempFolder . "/" . $vFilePrefix . "\" " . $program_to_run . " > \"" . $mTempFolder . "/" . $vFilePrefix . ".txt\"";
			}
			
			$execution_result = exec($toExec);
			
			Model_Shell_Debug::getInstance()->log("Java program compiled. Now executing: " . $toExec);
			// Model_Shell_Debug::getInstance()->log("The shell Execution result (not expecting anything) was: " . $execution_result);
			
			$vContents = file_get_contents(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".txt"));
			Model_Shell_Debug::getInstance()->log("File Execution result was: " . trim($vContents));
			
			// Delete all the stuff we made
			unlink("$mTempFolder/" . $vFilePrefix . ".java");
			unlink("$mTempFolder/" . $vFilePrefix . ".txt");
			Model_Utils_Filesystem::delete_directory($mTempFolder . "/" . $vFilePrefix);
			
			return $vContents;
		} else {
			return "Compilation failed! Reason:" . $execResult;
		}
	}
	
	static protected function java_win($mTempFolder, $vFilePrefix, $mSource) {
		// We have to make sure that the user has defined the JAVA Path (unlike Linux where it's just in the PATH)
		if (!defined("JAVAC_PATH")) {
			throw new Exception("The Windows Javac.exe Path is not defined. Please define it in general.php");
		} else {
			if (!file_exists(JAVAC_PATH)) {
				throw new Exception("The JavaC Path defined (Windows) is not accessible by this application");
			}
		}
		
		// Remove all old stuff
		if (file_exists("$mTempFolder/" . $vFilePrefix . ".java")) {
			@unlink(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".java"));
		}
		
		if (file_exists("$mTempFolder/" . $vFilePrefix . ".exe")) {
			@unlink(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".exe"));
		}
		
		My_Logger::log("$mTempFolder/" . $vFilePrefix . ".java");
		
		$fh = fopen(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".java"), 'w');
		fwrite($fh, $mSource);
		fclose($fh);
		
		My_Logger::log(file_get_contents("$mTempFolder/" . $vFilePrefix . ".java")); // apparently ok
		                                                                         
		// Java sometimes generates more than one class... so we put the compiled things in a directory
		mkdir($mTempFolder . "\\" . $vFilePrefix);
		$error_file = $mTempFolder . '\\' . $vFilePrefix . '.error.txt';
		$toExec = "\"" . JAVAC_PATH . "\" "
				. ' -g:none '
				. " \"" . $mTempFolder . "\\" . $vFilePrefix . ".java\" -d \"" . $mTempFolder . "\\" . $vFilePrefix . "\"" 
			. ' 2> "' . $error_file . '"' // capture any errors
		    ;
		    
		My_Logger::log('toExec: ' . $toExec);
		$execResult = exec($toExec);
		My_Logger::log('execResult: ' . $execResult);
		
		self::proccessErrorFile($error_file);
		
		// OK Now we need to see what classes have been generated in this directory, and then call that when executing
		$directory_contents = scandir($mTempFolder . "\\" . $vFilePrefix);
		if (sizeof($directory_contents) < 3) {
			throw new Exception("When Trying to generate a new randomised question, Compilation Failed. Reason: " . $execResult);
		}
		
		// At this point, I'm assuming we have only 1 main class
		$program_to_run = null;
		foreach ( $directory_contents as $dc ) {
			if (strlen($dc) > 5) {
				$program_to_run = str_replace(".class", "", $dc);
			}
		}
		
		if (!is_null($program_to_run)) {
			
			$toExec = "java -cp \"" . $mTempFolder . "\\" . $vFilePrefix . "\" " . $program_to_run . " > \"" . $mTempFolder . "\\" . $vFilePrefix . ".txt\"";
			$fh = fopen("$mTempFolder/" . $vFilePrefix . ".bat", 'w');
			fwrite($fh, $toExec);
			fclose($fh);
			
			// Apparently this line is trying to execute the generated .bat file, but since my os doesn't have the limitexec.exe utility
			// php trhows a warning and the expecte txt afterwards is not generated, so for now I'm commenting this out and
			// replacing with a direct call to the .bat file
			// $toExec = '"' . $mTempFolder . "\\limitexec.exe\" 5 ".$vFilePrefix;
			$toExec = $mTempFolder . "\\" . $vFilePrefix . ".bat";
			// echo "EXECUTING: " . $toExec;
			My_Logger::log(__METHOD__ . ": " . $toExec);
			exec($toExec);
			
			$vContents = file_get_contents(Model_Shell_Compiler::os_slash("$mTempFolder/" . $vFilePrefix . ".txt"));
			
			// Delete all the stuff we made
			/*
			 * unlink("$mTempFolder/".$vFilePrefix.".bat");
			 * unlink("$mTempFolder/".$vFilePrefix.".java");
			 * unlink("$mTempFolder/".$vFilePrefix.".txt");
			 * exec( "rmdir \"" . $mTempFolder . "\\" . $vFilePrefix . "\" /S /Q" );
			 */
			return $vContents;
		} else {
			return "Compilation failed! Reason:" . $execResult;
		}
	}
	
	/**
	 * Inspect the contents of the error.txt file for any compilation errors.
	 * If there were errors, throw and terminate
	 * @param string $error_file
	 * @throws CompilerException
	 * @author Ivan
	 */
	static protected function proccessErrorFile($error_file){
	    if (file_exists($error_file)) {
	    	$compiler_errors = file_get_contents($error_file);
	    	if (strlen($compiler_errors) > 0) {
	    		$cex = new CompilerException("There were compiler errors.");
	    		$cex->compiler_output = $compiler_errors;
	    		$cex->error_file = $error_file;
	    		My_Logger::log(__METHOD__ . " win compiler errors:\n" . $compiler_errors);
	    		throw $cex;
	    	}
	    }
	}
	
	
	
	
	/**
	 * C++ Specific Compilation
	 *
	 * @param string $mTempFolder 
	 * @param string $vFilePrefix 
	 * @param string $mSource 
	 * @return string
	 * @author Ben Evans
	 */
	protected static function cpp_compile_and_return($mTempFolder, $vFilePrefix, $mSource) {
				
		if(in_array(strtolower(PHP_OS), explode(",", self::NIX_OSES) )){
			// Linux, Mac etc
			
			if(file_exists(   Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix.".cpp")  )){
				unlink(  Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix.".cpp")  );
			}
			if(file_exists(  Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix) ) ){
				unlink(  Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix) );
			}
		
			$fh = fopen(   Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix.".cpp")  , 'w');
	        	fwrite($fh,$mSource);
	        	fclose($fh);

			$toExec = "g++ \"" . $mTempFolder . "/".$vFilePrefix.".cpp\" -o \"" . $mTempFolder . "/".$vFilePrefix."\"";
			//echo "EXECUTING: " . $toExec;
			$execResult = exec($toExec);
			die();

			//Now make sure it compiled
			if(file_exists("$mTempFolder/".$vFilePrefix)){
		                $toExec =  "timeout 5 \"" . $mTempFolder . "/".$vFilePrefix."\" > \"" . $mTempFolder . "/".$vFilePrefix.".txt\"";
	                
				//echo "EXECUTING: " . $toExec;
		                exec($toExec);
	
		                $vContents = file_get_contents("$mTempFolder/".$vFilePrefix.".txt");
	
		                //Delete all the stuff we made
	
		                unlink("$mTempFolder/".$vFilePrefix.".cpp");
		                unlink("$mTempFolder/".$vFilePrefix);
		                unlink("$mTempFolder/".$vFilePrefix.".txt");

	
		                return $vContents;

		        }else{
		                return "Compilation failed! Reason:" . $execResult;
		        }




		}else{
			
		//We're running Windows (probably)
		if(file_exists("$mTempFolder/".$vFilePrefix.".cpp")){
			unlink(   Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix.".cpp")  );
		}
		
		if(file_exists("$mTempFolder/".$vFilePrefix.".exe")){
			unlink(   Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix.".exe") );
		}

		$fh = fopen(  Model_Shell_Compiler::os_slash("$mTempFolder/".$vFilePrefix.".cpp"), 'w');
		fwrite($fh,$mSource);
		fclose($fh);
		
		$toExec = "C:\\mingw\bin\g++.exe \"" . $mTempFolder . "\\".$vFilePrefix.".cpp\" -o \"" . $mTempFolder . "\\".$vFilePrefix.".exe\" ";
		echo $toExec;
		$execResult = exec($toExec);

		if(file_exists("$mTempFolder/".$vFilePrefix.".exe")){
			$toExec =  "\"" . $mTempFolder . "\\".$vFilePrefix.".exe\" > \"" . $mTempFolder . "\\".$vFilePrefix.".txt\"";
			$fh = fopen("$mTempFolder/".$vFilePrefix.".bat", 'w');
			fwrite($fh,$toExec);
			fclose($fh);
		
		
			$toExec = '"' . $mTempFolder . "\\limitexec.exe\" 5 ".$vFilePrefix;
			//echo "EXECUTING: " . $toExec;
			exec($toExec);	
		
			$vContents = file_get_contents( Model_Shell_Compiler::os_slash( "$mTempFolder/".$vFilePrefix.".txt" ) );
		
			//Delete all the stuff we made
		
			unlink("$mTempFolder/".$vFilePrefix.".cpp");
			unlink("$mTempFolder/".$vFilePrefix.".bat");
			unlink("$mTempFolder/".$vFilePrefix.".exe");
			unlink("$mTempFolder/".$vFilePrefix.".txt");
		
		
			return $vContents;
	
		}else{
			return "Compilation failed! Reason:" . $execResult;
		}

		}//End Windows-specific code
		
	}
	
	
	
	
	
	
	
	/**
	 * This function will take a file path (regardless of how it's specified),
	 * and make it compatible for whatever OS this server is running. I WOULD use
	 * DIRECTORY SEPARATOR, but it has issues with encryption etc.
	 *
	 * @param string $file_path 
	 * @return string
	 * @author Ben Evans
	 */
	public static function os_slash( $file_path ) {
		if( strpos($file_path, "../") !== FALSE || strpos($file_path, "..\\") !== FALSE ) {
			// Windows doesn't like ../ paths
			$file_path = str_replace("\\", "/", $file_path);
			$paths = explode("/", $file_path);
			
			for($i = 1; $i < sizeof($paths); $i++) {
				if( $paths[$i] == ".." ) {
					unset( $paths[$i] );
					unset( $paths[$i-1] );
				}
			}
			
			$file_path = implode("/", $paths);
		}
		
		
		if(in_array(strtolower(PHP_OS), explode(",", self::NIX_OSES) )){
			return str_replace("\\", "/", $file_path);
		}else{
			return str_replace("/", "\\", $file_path);
		}
	}
	
	
	
}

class CompilerException extends Exception{
    public  $error_file = false;
    public  $compiler_output;

	function __construct($message="",$code=0, $previous=null){
        parent::__construct($message, $code, $previous);
    }
} 
