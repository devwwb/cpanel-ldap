<?php 
session_start();
require_once 'classes/class.ldap.php';
$Ldap= new LDAP();

$current_page=basename(__FILE__);
$Ldap->check_login_or_redirect($current_page);

require_once('header.php');
//connect and BInd
$errorttpe="";
$message=$dns_result="";
$statok='<i class="fa fa-check-circle-o icon checkok"></i>';
$loading='<span class="loading"></span>';
$ldapconn=$Ldap->connect();
$psw=$Ldap->decrypt_psw();
if ($ldapconn){
  $ldapbind=$Ldap->bind($ldapconn,$_SESSION["login"]["dn"]  ,$psw); 
  $permissions= $_SESSION["login"]["level"];
  switch ($permissions) :
  case "10" :
    $binddn=LDAP_BASE;
    $filter="(vd=*)";
  break;
        case "4" :
        $binddn=LDAP_BASE;
    $who=$_SESSION["phamm"]["domain"];
    $filter="(vd=" . $who .")";
    
  break;
  case "2":
    $who= $_SESSION['login']["username"];
    $binddn="vd=".$_SESSION["phamm"]["domain"].",".LDAP_BASE;           
    $filter="(mail=" . $who .")";
  break;
  default:
  break;
  endswitch;
}
//Add new domain
if(isset($_POST['adddomain'])){
    $webmaster=$_POST['seluser'];
    if($webmaster=='newuser'){
      //$webmaster=$_POST['new_username'];
            /*$password = $_POST['webmaster_password'];
            $add_user=$Ldap->add_sftp_user($webmaster,$password);
            $message=$add_user['message'];*/
        $ldaptree    = LDAP_PEOPLE;
        $groupinfo = posix_getgrnam("sftpusers");
        $grid=$groupinfo["gid"];
        $entryus=array();
        $newuser=trim($_POST['username']);
        $first_name=(isset($_POST['firstname']))?$_POST['firstname']:$newuser;
        $second_name=(isset($_POST['surname']))?$_POST['surname']:$newuser;
        $user_email=trim($_POST['usermail']);
        $password=$_POST['pswd2'];
          $entryus['gidnumber']=(int)$grid;
          $entryus['loginshell']='/bin/bash';
          $entryus['homedirectory']='/home/sftpusers/' . $newuser;
          $entryus['authorizedservice'][0]='sshd';
        if (isset($_POST['vpn'])){
          $entryus['authorizedservice'][1]='openvpn';
          //$entry['homedirectory']='none';
        }
          $entryus['uid']=$newuser;
          $entryus['cn']=(!empty($first_name))?$first_name:$newuser;
          $entryus['sn']=(!empty($second_name))?$second_name:$newuser;
          $entryus['mail']=$user_email;
          $entryus['userpassword']=ldap_password_hash($password,'ssha');
          $add_user=$Ldap->add_user($newuser,$entryus);
          if($add_user['result'] == 1){
            // If we could create the new user assign it as adminid
            // else we will assign the default user as admin
            $webmaster=$newuser;
            $usererro="";
          } else {
            $wemaster=$Ldap->get_sudo_user();
            $usererror = sprintf (_("El usuario %s no se ha podido crear.Se ha establecido el usuario por defecto %s como administrador del dominio"),$newuser,$wemaster);
          }

          if (isset($_POST["sendinstruction"]) && $add_user)$Ldap->send_vpn_instructions($user_email,$newuser);
          $message=$add_user['message'];
    }
    $values = $_POST["values"];
    $domain_new = $_POST["domain_new"];

    # Check if DNS are ok for this server
    $check_dns=check_domain_dns($domain_new);
    $dns_result=$check_dns["message"];

    $syntax= check_syntax ('domain',$domain_new);
    $password = $_POST["password"];
    $entry["objectclass"][0]    = "top";
    $entry["objectclass"][1]    = "VirtualDomain";
    $entry["vd"]                = $domain_new;
    $entry["lastChange"]        = time();
    $entry["adminid"]           = $webmaster;
    // Merge static values with domain values
    $entry = array_merge($entry,$values["domain"]);
  ######Create cn=Postmaster ###########3
    $entrypm = array();
    $entrypm["objectclass"][0]    = "top";
    $entrypm["objectclass"][1]    = "VirtualMailAlias";
    $entrypm["cn"] = "Postmaster";
    $entrypm["sn"] = "Postmaster";
    $entrypm["mail"] = "postmaster@".$domain_new;
    $entrypm["userPassword"]  =ldap_password_hash($password,'md5crypt');
    $entrypm["maildrop"] = "postmaster";
    $entrypm["accountActive"]     = "TRUE";
    $entrypm["creationDate"]      = date('Ymd');
    $entrypm["lastChange"]        = time();
    // Create abuse alias
    $entry_abuse["objectclass"][0]  = "top";
    $entry_abuse["objectclass"][1]  = "VirtualMailAlias";
    $entry_abuse["cn"] = "Abuse";
    $entry_abuse["sn"] = "Abuse";
    $entry_abuse["mail"] = "abuse@".$domain_new;
    $entry_abuse["maildrop"] = "postmaster";
    $entry_abuse["accountActive"] = "TRUE";
    $entry_abuse["creationDate"] = date('Ymd');
    $entry_abuse["lastChange"] = time();
    // iCheck Domain syntax
    if (!$syntax){
      $errorttpe="El dominio " . $domain_new . " no es válido";
    } else {
      //if syntax is ok add records     
      $addDomain=$Ldap->addRecord($ldapconn, 'vd='.$domain_new.','.LDAP_BASE, $entry);
      $addDomainpm=$Ldap->addRecord($ldapconn, 'cn=postmaster,vd='.$domain_new.','.LDAP_BASE, $entrypm);
      $addAbuse=$Ldap->addRecord($ldapconn,'mail=abuse@'.$domain_new.',vd='.$domain_new.','.LDAP_BASE,$entry_abuse); 
    }
    if ($addDomain && $addAbuse && $addDomainpm) {
       $message .= "
    <div class='alert alert-success'>
    <button class='close' data-dismiss='alert'>&times;</button>
    <strong>Dominio " . $domain_new ." añadido correctamente.<br>"
    . $dns_result . "</strong>
    </div>
        ";

       //We have to create the .ini file for rainloop for this domain
       //if rainloop is installed in /var/www/rainloop/data/_data_/_default_/domains/
      if ($Ldap->check_installed_service('rainloop')){
          $filename ='/var/www/rainloop/data/_data_/_default_/domains/'. $domain_new .'.ini';
          if (!file_exists( $filename)){
            $ini_file= fopen($filename, "w") or die("Unable to open file!");
            $fqdn=trim(shell_exec('hostname -f'));
            $content='imap_host = "' . $fqdn . '"
imap_port = 143
imap_secure = "TLS"
imap_short_login = Off
sieve_use = Off
sieve_allow_raw = Off
sieve_host = ""
sieve_port = 4190
sieve_secure = "None"
smtp_host = "' . $fqdn . '"
smtp_port = 25
smtp_secure = "TLS"
smtp_short_login = Off
smtp_auth = On
smtp_php_mail = Off
white_list = ""';

              fwrite($ini_file, $content);
              fclose($ini_file);
          }
        }
      } else {
          $errorttpe    = (ldap_errno($ldapconn)==68)?"El dominio " . $domain_new . " ya existe": $errorttpe;
          $message .=  "
    <div class='alert alert-error'>
    <button class='close' data-dismiss='alert'>&times;</button>
    <strong>Ha habido un error. " . $errorttpe ." </strong> 
    </div>
    ";
      }
  
}
?>
<div id="admin-content" class="content">
    <?php if($message) echo $message;?>
    <?php if($_SESSION["login"]["level"] == '10'){//Only admin can add Domains 
    ?>
    <form autocomplete="off" action="" method="POST" class="form-signin jquery-check">
        <hr>
        <label for="domain"><?php printf (_("Nombre de Dominio"))?> </label><p class="little"> <?php printf (_("(Inserta un nombre de dominio válido (o un subdominio). Para los dominios activados en este panel podrás crear cuentas de correo electrónico o páginas web)"))?></p><input id="domain_new" type="text" name="domain_new" required />
        <label for="password"><?php printf (_("Contraseña:"));?> </label><p class="little">Esta contraseña se puede utilizar para acceder a este mismo panel de control como administrador del dominio identificándose con <b>User:</b> <em>Nombre Dominio</em> <b>Contraseña: </b><em>La que insertes en este campo</em>. El administrador de dominio tiene privilegios límitados y sólo podrá crear, editar y borrar las cuentas de correo electrónico asociadas a su dominio. No podrá en ningún caso acceder a ninguna otra función y no podrá eliminar el dominio</p><input id="password" type="password" name="password" required />
        <label for="webmaster"><?php printf (_("Webmaster (Administrador sito web)"));?> </label><p class="little">Por cada dominio que actives en este panel se creará una carpeta en la que puedes subir tu aplicación web, accesible desde un navegador. Pudedes permitir que un usuario concreto tenga acceso a la carpeta para que pueda editar sus archivos. Puedes elegir un usuario ya creado o crear uno nuevo. Si no asignas ningún usuario solo podrá editar los archivos el usuario por defecto del sistema, tanto por ssh como por sftp</p>
         <?php 
        $ldaptree    = LDAP_PEOPLE;
        $filter="(&(objectClass=person)(uid=*)(authorizedService=sshd)(!(gidnumber=27)))";
        $filtersudo="(&(objectClass=person)(uid=*)(gidnumber=27))";
        $allusers=$Ldap->search($ldapconn,$ldaptree, $filter);
        $sudouser=$Ldap->search($ldapconn,$ldaptree, $filtersudo);
        // This is the default user which has sudo and will be the default owner
        // in case no webmaster is selected or created
        $sudo_username=$sudouser[0]["uid"][0];
        //default webmaster will be user with sudo (uid 10000);
        echo '<label for="seluser">' . sprintf (_("Asignar administrador web")) . '</label>';
        echo '<select id="seluser" name="seluser">';
        //echo '<option value="' . $sudo_username .'">Seleccionar Administrador web</option>';
        echo '<option value="' . $sudo_username .'">' . $sudo_username .' - Usuario por defecto</option>';
        echo '<option value="newuser">Crear nuevo usuario</option>';
        for ($c=0; $c<$allusers["count"]; $c++) {
            $usernames = $allusers[$c]["uid"][0];
            echo '<option value="' . $allusers[$c]["uid"][0] .'">' . $allusers[$c]["uid"][0] . '</option>';
                  }
            echo '</select>';
            echo '<div class="newuser" id="new_user">';
            echo '
            <label for="username">' . sprintf(_("Nombre de usuario")) .' *</label>
            <input id="username" type="text" name="username" /><div id="result"></div>
            <label for="firstname">'. sprintf(_("Primer nombre (Opcional)")) .'</label>
            <input id="firstname" type="text" name="firstname" />
            <label for="surname">' .  sprintf(_("Apeliidos (Opcional)")) . '</label>
            <input id="surname" type="text" name="surname" />
            <label for="usermail">' . sprintf(_("Correo electrónico")) .'* </label>
            <p class="little">Puedes insertar un correo electrónico externo o elegir una entre las cuentas creadas en el servidor</p>
            <input id="usermail" class="usermail" type="mail" name="usermail" />';
            $resultmail = $Ldap->search($ldapconn,LDAP_BASE,'(&(objectClass=VirtualMailAccount)(!(cn=postmaster))(!(mail=abuse@*)))');
            $mailcount = $resultmail["count"];
            if($mailcount>0) {
                    echo '<select id="selmail">';
                    echo '<option value="">' . sprintf(_("Seleccionar cuenta existente")) . '</option>';
                    for ($c=0; $c<$resultmail["count"]; $c++) {
                          echo '<option value="' . $resultmail[$c]["mail"][0] .'">' . $resultmail[$c]["mail"][0] . '</option>';
                    }
                   echo '</select>';
            };?>
            <div id="emailresult"></div>

            <hr>
            <?php if ($Ldap->check_installed_service('openvpn')){?>
            <h4><?php printf(_("Cuenta VPN"));?></h4>
            <input type="checkbox" name="vpn" id="vpn" />
            <label for="vpn" class="togglehidden" >&nbsp;</label></h4>

            <div id="hidden">
              <h4><?php printf(_("Instrucciones"));?></h4>
              <p><?php printf(_("Puedes enviar al usuario un email con instrucciones para configurar el cliente VPN"));?></p>
              <p><?php printf(_("NOTA: Las instrucciones incluyen todos los datos necesarios menos la contraseña. Por razones de seguridad proporciona al usuario la  contraseña por otro canal"));?></p>
              <input type="checkbox" name="sendinstruction" id="sendinstruction" />
              <label for="sendinstruction" class="left small">&nbsp;</label>&nbsp;<span><?php printf(_("Enviar instrucciones"));?></span></h4>
            </div>
            <?php } ?>
            <hr>
            <h4><?php printf (_("Contraseña personal de usuario"));?></h4>
            <label for="pswd1"><?php printf(_("Contraseña"));?> *</label>
            <div id="pswcheck"></div>
            <input id="pswd1" type="password" name="pswd1" />
            <label for="pswd2"><?php printf(_("Confirma contraseña"));?> *</label>
            <input id="pswd2" type="password" name="pswd2"  />
            <div id="pswresult"></div>
            <hr>

            </div>

            <div class="clear"></div>
            <hr>
            <input class="form-control" type="hidden" name="values[domain][maxmail]" value="100">
            <input class="form-control" type="hidden" name="values[domain][maxalias]" value="100">
            <input class="form-control" type="hidden" name="values[domain][maxquota]" value="100">
            <input class="form-control" type="hidden" name="values[domain][accountactive]" value="TRUE">
            <input class="form-control" type="hidden" name="values[domain][editav]" value="TRUE">
            <input class="form-control" type="hidden" name="values[domain][delete]" value="FALSE">
            <input class="form-control" type="hidden" name="values[mail][editaccounts]" value="TRUE">
            <input class="form-control" type="hidden" name="values[domain][postfixtransport]" value="maildrop:">
            <input type="submit" name="adddomain" value="Guardar" class="btn btn-small btn-primary" />

            </form>
        <?php } ?>
        </tbody>
    </table>
  </div><!--ineer-->

</div><!--admin-content-->
<?php 
  ldap_close($ldapconn);
  require_once('footer.php');?>
