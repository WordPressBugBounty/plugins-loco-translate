<?php
/**
 * 
 */
class Loco_fs_File {
    
    /**
     * @var Loco_fs_FileWriter
     */
    private $w;

    /**
     * Path to file
     * @var string
     */
    private $path;
    
    /**
     * Cached pathinfo() data 
     * @var array
     */
    private $info;

    /**
     * Base path which path has been normalized against
     * @var string
     */
    private $base;

    /**
     * Flag set when current path is relative
     * @var bool
     */
    private $rel;


    /**
     * Check if a path is absolute and return fixed slashes for readability
     * @param string $path
     * @return string fixed path, or "" if not absolute
     */
    public static function abs( $path ){
        $path = (string) $path;
        if( '' !== $path ){
            $chr1 = substr($path,0,1);
            // return unmodified path if starts "/"
            if( '/' === $chr1 ){
                return $path;
            }
            // Windows drive path if "X:" or network path if "\\"
            $chr2 = (string) substr($path,1,1);
            if( '' !== $chr2 ){
                if( ':' === $chr2 ||  ( '\\' === $chr1 && '\\' === $chr2 ) ){
                    return strtoupper($chr1).$chr2.strtr( substr($path,2), '\\', '/' );
                }
            }
        }
        // else path is relative, so return falsy string
        return '';
    }


    /**
     * Test if a path looks absolute
     */
    public static function is_abs( $path ){
        return '' !== $path && ( '/' === $path[0] || preg_match('!^\\\\\\\\|.:\\\\!',$path) );
    }


    /**
     * Call PHP is_readable() but suppress E_WARNING when path is outside open_basedir.
     * @param string $path
     * @return bool
     */
    public static function is_readable( $path ){
        if( '' === $path || '.' === $path[0] ){
            throw new InvalidArgumentException('Relative paths disallowed');
        }
        // Reduce PHP errors from is_readable to debug messages
        Loco_error_AdminNotices::capture(E_NOTICE|E_WARNING);
        $bool = is_readable($path);
        restore_error_handler();
        return $bool;
    }


    /**
     * Create file with initial, unvalidated path
     * @param string $path
     */    
    public function __construct( $path ){
        $this->setPath( $path );
    }


    /**
     * Internally set path value and flag whether relative or absolute
     * @param string $path
     * @return void
     */
    private function setPath( $path ){
        $path = (string) $path;
        if( $fixed = self::abs($path) ){
            $path = $fixed;
            $this->rel = false;
        }
        else {
            $this->rel = true;
        }
        if( $path !== $this->path ){
            $this->path = $path;
            $this->info = null;
        }
    }


    /**
     * @return bool
     */
    public function isAbsolute(){
        return ! $this->rel;
    }


    /**
     * @internal
     */
    public function __clone(){
        $this->cloneWriteContext( $this->w );
    }


    /**
     * Copy write context with our file reference
     */
    private function cloneWriteContext( ?Loco_fs_FileWriter $context = null ):void {
        if( $context ){
            $context = clone $context;
            $this->w = $context->setFile($this);
        }
    }


    /**
     * Get file system context for operations that *modify* the file system.
     * Read operations and operations that stat the file will always do so directly.
     */
    public function getWriteContext():Loco_fs_FileWriter {
        if( ! $this->w ){
            $this->w = new Loco_fs_FileWriter( $this );
        }
        return $this->w;
    }


    /**
     * @internal
     */
    private function pathinfo(){
        return is_array($this->info) ? $this->info : ( $this->info = pathinfo($this->path) );
    }


    /**
     * Checks if a file exists, and is within open_basedir restrictions.
     * This does NOT check if file permissions allow PHP to read it. Call $this->readable() or self::is_readable($path).
     */
    public function exists():bool {
        return file_exists($this->path);
    }


    /**
     * Check if file is writable by the current write context
     */
    public function writable():bool {
        return $this->getWriteContext()->writable();
    }


    /**
     * Check if the file exists and is readable by the current PHP process.
     */
    public function readable():bool {
        return self::is_readable($this->path);
    }


    /**
     * Check if file is removable by the current write context
     */
    public function deletable():bool {
        $parent = $this->getParent();
        if( $parent && $parent->writable() ){
            // sticky directory requires that either the file its parent is owned by effective user
            if( $parent->mode() & 01000 ){
                $writer = $this->getWriteContext();
                if( $writer->isDirect() && ( $uid = Loco_compat_PosixExtension::getuid() ) ){
                    return $uid === $this->uid() || $uid === $parent->uid();
                }
                // else delete operation won't be done directly, so can't preempt sticky problems
                // TODO is it worth comparing FTP username etc.. for ownership?
            }
            // defaulting to "deletable" based on fact that parent is writable.
            return true;
        }
        return false;
    }


    /**
     * Get owner uid
     * @return int|false
     */
    public function uid(){
        return fileowner($this->path);
    }


    /**
     * Get group gid
     * @return int|false
     */
    public function gid(){
        return filegroup($this->path);
    }


    /**
     * Check if file can't be overwritten when existent, nor created when non-existent
     * This does not check permissions recursively as directory trees are not built implicitly
     */
    public function locked():bool {
        if( $this->exists() ){
            return ! $this->writable();
        }
        if( $dir = $this->getParent() ){
            return ! $dir->writable();
        }
        return true;
    }


    /**
     * Check if full path can be built to non-existent file.
     */
    public function creatable():bool {
        $file = $this;
        while( $file = $file->getParent() ){
            if( $file->exists() ){
                return $file->writable();
            }
        }
        return false;
    }
    
    
    /**
     * @return string
     */
    public function dirname(){
        $info = $this->pathinfo();
        return $info['dirname'];
    }

    
    /**
     * @return string
     */
    public function basename(){
        $info = $this->pathinfo();
        return $info['basename'];
    }

    
    /**
     * @return string
     */
    public function filename(){
        $info = $this->pathinfo();
        return $info['filename'];
    }


    /**
     * Gets final file extension, e.g. "html" in "foo.php.html"
     * @return string
     */
    public function extension(){
        $info = $this->pathinfo();
        return $info['extension'] ?? '';
    }


    /**
     * Gets full file extension after first dot ("."), e.g. "php.html" in "foo.php.html"
     * @return string
     */
    public function fullExtension(){
        $bits = explode('.',$this->basename(),2);
        return array_key_exists(1,$bits) ? $bits[1] : '';
    }


    /**
     * @return string
     */
    public function getPath(){
        return $this->path;
    }


    /**
     * Get file modification time as unix timestamp in seconds
     * @return int
     */
    public function modified(){
        return filemtime( $this->path );
    }


    /**
     * Get file size in bytes
     * @return int
     */
    public function size(){
        return filesize( $this->path );
    }


    /**
     * @return int
     */
    public function mode(){
        if( is_link($this->path) ){
            $stat = lstat( $this->path );
            $mode = $stat[2];
        }
        else {
            $mode = fileperms($this->path);
        }
        return $mode;
    }
    

    /**
     * Set file mode
     * @param int $mode file mode integer e.g 0664
     * @param bool $recursive whether to set recursively (directories)
     * @return Loco_fs_File
     */
    public function chmod( $mode, $recursive = false ){
        $this->getWriteContext()->chmod( $mode, $recursive );
        return $this->clearStat();
    }

    
    /**
     * Clear stat cache if any file data has changed
     * @return Loco_fs_File
     */
    public function clearStat(){
        $this->info = null;
        // PHP 5.3.0 Added optional clear_realpath_cache and filename parameters.
        if( version_compare( PHP_VERSION, '5.3.0', '>=' ) ){
            clearstatcache( true, $this->path );
        }
        // else no choice but to drop entire stat cache
        else {
            clearstatcache();
        }
        return $this;
    }

    
    /**
     * @return string
     */
    public function __toString(){
        return $this->getPath();
    }


    /**
     * Check if passed path is equal to ours
     * @param string|self $ref
     * @return bool
     */
    public function equal( $ref ){
        return $this->path === (string) $ref;
    }


    /**
     * Normalize path for string comparison, resolves redundant dots and slashes.
     * @param string $base path to prefix
     * @return string
     */
    public function normalize( $base = '' ){
        if( $path = self::abs($base) ){
            $base = $path;
        }
        if( $base !== $this->base ){
            $path = $this->path;
            if( '' === $path ){
                $this->setPath($base);
            }
            else {
                if( ! $this->rel || ! $base ){
                    $b = [];
                }
                else {
                    $b = self::explode( $base, [] );
                }
                $b = self::explode( $path, $b );
                $this->setPath( implode('/',$b) );
            }
            $this->base = $base;
        }
        return $this->path;
    }


    /**
     * Get real path if file is real, but without altering internal path property.
     * Also skips call to realpath() when likely to raise E_WARNING due to open_basedir
     * @return string
     */
    public function getRealPath(){
        if( $this->readable() ){
            $path = realpath( $this->getPath() );
            if( is_string($path) ){
                return $path;
            }
        }
        return '';
    } 


    /**
     * @param string $path
     * @param string[] $b
     * @return array
     */
    private static function explode( $path, array $b ){
        $a = explode( '/', $path );
        foreach( $a as $i => $s ){
            if( '' === $s ){
                if( 0 !== $i ){
                    continue;
                }
            }
            if( '.' === $s ){
                continue;
            }
            if( '..' === $s ){
                if( array_pop($b) ){
                    continue;
                }
            }
            $b[] = $s;
        }
        return $b;
    }


    /**
     * Get path relative to given location, unless path is already relative
     * @param string $base Base path
     * @return string path relative to given base
     */
    public function getRelativePath( $base ){
        $path = $this->normalize();
        if( self::abs($path) ){
            // base may require normalizing
            $file = new Loco_fs_File($base);
            $base = $file->normalize();
            $length = strlen($base)+1;
            // if we are below given base path, return ./relative
            if( substr($path,0,$length) === $base.'/' ){
                if( strlen($path) > $length ){
                    return substr( $path, $length );
                }
                // else paths were identical
                return '';
            }
            // else attempt to find nearest common root
            $i = 0;
            $source = explode('/',$base);
            $target = explode('/',$path);
            while( isset($source[$i]) && isset($target[$i]) && $source[$i] === $target[$i] ){
                $i++;
            }
            if( $i > 1 ){
                $depth = count($source) - $i;
                $build = array_merge( array_fill( 0, $depth, '..' ), array_slice( $target, $i ) );
                $path = implode( '/', $build );
            }
        }
        // else return unmodified
        return $path;
    }


    /**
     * Test if file is a directory
     */
    public function isDirectory():bool {
        if( $this->readable() ){
            return is_dir($this->path);
        }
        return '' === $this->extension();
    }



    /**
     * Load contents of file into a string
     */
    public function getContents():string {
        return file_get_contents($this->path);
    }


    /**
     * Check if path is under a theme directory 
     */
    public function underThemeDirectory():bool {
        return Loco_fs_Locations::getThemes()->check( $this->path );
    }


    /**
     * Check if path is under a plugin directory 
     */
    public function underPluginDirectory():bool {
        return Loco_fs_Locations::getPlugins()->check( $this->path );
    }


    /**
     * Check if path is under wp-content directory 
     */
    public function underContentDirectory():bool {
        return Loco_fs_Locations::getContent()->check( $this->path );
    }


    /**
     * Check if path is under WordPress root directory (ABSPATH) 
     */
    public function underWordPressDirectory():bool {
        return Loco_fs_Locations::getRoot()->check( $this->path );
    }


    /**
     * Check if path is under the global system directory 
     */
    public function underGlobalDirectory():bool {
        return Loco_fs_Locations::getLangs()->check( $this->path );
    }


    /**
     * @return Loco_fs_Directory|null
     */
    public function getParent():?Loco_fs_Directory {
        $dir = null;
        $path = $this->dirname();
        if( '.' !== $path && $this->path !== $path ){ 
            $dir = new Loco_fs_Directory( $path );
            $dir->cloneWriteContext( $this->w );
        }
        return $dir;
    }


    /**
     * Copy this file for real
     * @param string $dest new path
     * @throws Loco_error_WriteException
     * @return Loco_fs_File new file
     */
    public function copy( $dest ){
        $copy = clone $this;
        $copy->path = $dest;
        $copy->clearStat();
        $this->getWriteContext()->copy($copy);
        return $copy;
    }


    /**
     * Move/rename this file for real
     * @param Loco_fs_File $dest target file with new path
     * @throws Loco_error_WriteException
     * @return Loco_fs_File original file that should no longer exist
     */
    public function move( Loco_fs_File $dest ){
        $this->getWriteContext()->move($dest);
        return $this->clearStat();
    }


    /**
     * Delete this file for real
     * @throws Loco_error_WriteException
     * @return Loco_fs_File
     */
    public function unlink(){
        $recursive = $this->isDirectory();
        $this->getWriteContext()->delete( $recursive );
        return $this->clearStat();
    }


    /**
     * Copy this object with an alternative file extension
     * @param string $ext new extension
     * @return self
     */
    public function cloneExtension( $ext ){
        return $this->cloneBasename( $this->filename().'.'.ltrim($ext,'.') );
    }


    /**
     * Copy this object with an alternative name under the same directory
     * @param string $name new name
     * @return self
     */
    public function cloneBasename( $name ){
        $file = clone $this;
        $file->path = rtrim($file->dirname(),'/').'/'.$name;
        $file->info = null;
        return $file;
    }


    /**
     * Ensure full parent directory tree exists
     * @return Loco_fs_Directory|null
     */
    public function createParent(){
        $dir = $this->getParent();
        if( $dir instanceof Loco_fs_Directory && ! $dir->exists() ){
            $dir->mkdir();
        }
        return $dir;
    }


    /**
     * @param string $data file contents
     * @return int number of bytes written to file
     */
    public function putContents( string $data ):int {
        $this->getWriteContext()->putContents($data);
        $this->clearStat();
        return $this->size();
    }


    /**
     * Establish what part of the WordPress file system this is.
     * Value is that used by WP_Automatic_Updater::should_update.
     * @return string "core", "plugin", "theme" or "translation"
     */
    public function getUpdateType(){
        // global languages directory root, and canonical subdirectories
        $dirpath = (string) ( $this->isDirectory() ? $this : $this->getParent() );
        $sub = Loco_fs_Locations::getGlobal()->rel($dirpath);
        if( is_string($sub) && '' !== $sub ){
            list($root) = explode('/', $sub, 2 );
            if( '.' === $root || 'themes' === $root || 'plugins' === $root ){
                return 'translation';
            }
        }
        // theme and plugin locations can be at any depth
        else if( $this->underThemeDirectory() ){
            return 'theme';
        }
        else if( $this->underPluginDirectory() ){
            return 'plugin';
        }
        // core locations are under WordPress root, but not under wp-content
        else if( $this->underWordPressDirectory() && ! $this->underContentDirectory() ){
            return 'core';
        }
        // else not an update type
        return '';
    }


    /**
     * Get MD5 hash of file contents
     */
    public function md5():string {
        if( $this->exists() ) {
            return md5_file( $this->path );
        }
        else {
            return 'd41d8cd98f00b204e9800998ecf8427e';
        }
    } 

}
