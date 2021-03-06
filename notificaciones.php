<?php 

session_start();
require_once 'classes/class.ldap.php';
$Ldap= new LDAP();

$current_page=basename(__FILE__);
$Ldap->check_login_or_redirect($current_page);

$message='';
require_once('header.php');
//connect and BInd
$ldapconn=$Ldap->connect();
$psw=$Ldap->decrypt_psw();
if ($ldapconn){
  $ldapbind=$Ldap->bind($ldapconn,$_SESSION["login"]["dn"],$psw); 

  #TODO: Check user level to show and allow differents permissions
  #Level 10= admin : can read and manage all accounts
  #Level 4 postmaster (domain administrator) can read and edit all accounts related to his domain excluded VPN
  #level 2 : simple user. Can read and edit only his own data (basically email related)
  #need LDAP ACL to be set
  #

  $permissions= $_SESSION["login"]["level"];
  $binddn=LDAP_BASE;
  $filter="(vd=*)";

//Modifiy sender email account 
  if(isset($_POST['chmail_notif'])){
    $modifydn=$_POST['selmail'];
    #$modifs['objectClass'][0] = 'metaInfo';
    #$modifydn='mail=' . $mailaccount . ',vd='.$domain.','.LDAP_BASE;
    $entry["cn"] =$modifydn; 
    ldap_mod_replace($ldapconn,'ou=sendermail,' . SUFFIX, $entry);
      }
  


  
//Check if a custom mail has been set
if ($ldapbind) {
  $mailsenderou= $Ldap->search($ldapconn,'ou=sendermail,' . SUFFIX ,'(&(objectClass=organizationalUnit)(objectClass=metaInfo))');
}
//Check if object senderemail existe. if not create it
if(!$mailsenderou){
    $senderemail_exist=$Ldap->search($ldapconn,SUFFIX,"(ou=senderemail)");
    if ($senderemail_exist["count"]==0){
        //create ou=sendermail if not exist
        $Ldap->addSenderObject($ldapconn);
    }
}

  $fqdn=trim(shell_exec('hostname -f'));
  $sender_email = (isset($mailsenderou[0]["cn"][0]))?$mailsenderou[0]["cn"][0]: 'www-data@'.$fqdn;

  $result = $Ldap->search($ldapconn, LDAP_BASE,'(&(objectClass=VirtualMailAccount)(!(cn=postmaster))(!(mail=abuse@*)))');
}?>

<div id="admin-content" class="content">
        <div class="col-sm-12">
            <div class="inner"i id="maincol">
      <?php
      echo $message; 
          echo '<p>Tu sistema puede enviar emails de notificaciones a los usuarios. Por ejemplo, si creas una cuenta VPN, puedes enviar instrucciones al usuario para configurar correctamente su conexión.<br>
            Actualmente, el correo electrónico desde el que se envian los email del sistema es:<br>
        <pre>'. $sender_email .'</pre>';
      if($result["count"] == 0){
        echo ' Todavia no has activado ninguna cuenta de correo en el servidor. Una vez haya creado almenos una, podrás designarla como remitente para las notificaciones';

      } else {
        echo 'Puedes cambiar esta configuración y elegir una entre  las cuentas email activadas en tu sistema, para que sea el remitente de las notificaciones.<br>
        Para cambiar este valor, elije un correo electrónico disponible en el listado y haz click en Guardar';?>

          <form autocomplete="off" action="" method="POST" class="form-signin">
        <hr>
        <?php
                echo '<select id="selmail" name="selmail" required>';
                echo '<option value="">Seleccionar email</option>';
                for ($c=0; $c<$result["count"]; $c++) {
                    //$selected=($queryvar==$result[$c]["mail"][0])?"selected":"";
                    echo '<option value="' . $result[$c]["mail"][0] .'">' . $result[$c]["mail"][0] . '</option>';
                }
                echo '<option value="www-data@'.$fqdn . '">www-data@'.$fqdn . '</option>';
                echo '</select></span>';
              };?>

                <br>
                <hr>
              <input type="submit" name="chmail_notif" value="Guardar" class="btn btn-small btn-primary" />
            </form>
      <?php 
     //end if domain not =  0?>
    </div><!--change-->

            </div><!--ineer-->
        </div><!--col-sm-8-->

  </div><!--row-->
<?php
?>
</div><!--admin-content-->
<?php
  ldap_close($ldapconn);   
  require_once('footer.php');?>


