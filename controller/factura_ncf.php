<?php
/*
 * This file is part of FacturaSctipts
 * Copyright (C) 2014  Valentín González    valengon@hotmail.com
 * Copyright (C) 2014-2015  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'plugins/republica_dominicana/fpdf17/fs_fpdf.php';
define('FPDF_FONTPATH', 'plugins/republica_dominicana/fpdf17/font/');
define('EEURO', chr(128));
require_model('cliente.php');
require_model('factura_cliente.php');
require_model('articulo.php');
require_model('divisa.php');
require_model('pais.php');
require_model('forma_pago.php');
require_model('ncf_ventas.php');
require_model('ncf_tipo.php');
require_model('ncf_entidad_tipo.php');
require_model('agente.php');

//if(class_exists('distrib_creacion')){
    require_model('distribucion_ordenescarga_facturas.php');
//}

require_once 'extras/phpmailer/class.phpmailer.php';
require_once 'extras/phpmailer/class.smtp.php';

class factura_ncf extends fs_controller {

   public $cliente;
   public $factura;
   public $ncf_ventas;
   public $distrib_transporte;
   public $idtransporte;
   public $archivo;
   public $rd_setup;
   public $agente;

   public function __construct() {
      parent::__construct(__CLASS__, 'Factura NCF', 'ventas', FALSE, FALSE);
   }

   protected function private_core()
   {
      $this->template = false;
      $this->share_extensions();
      
      //Obtenemos las configuraciones de impresión de las facturas de RD
      $fsvar = new fs_var();
      $this->rd_setup = $fsvar->array_get(
        array(
            'rd_imprimir_logo' => 'TRUE',
            'rd_imprimir_marca_agua' => 'TRUE',
            'rd_imprimir_bn' => 'FALSE',
        ), FALSE
     );
      
      $val_id = \filter_input(INPUT_GET, 'id');
      $solicitud = \filter_input(INPUT_GET, 'solicitud');
      $valores_id = explode(',', $val_id);
      if(class_exists('distribucion_ordenescarga_facturas')){
        $this->distrib_transporte = new distribucion_ordenescarga_facturas();
      }
      
      
      if(class_exists('agente')){
        $this->agente = new agente();
      }

      if(!empty($valores_id[0]) AND $solicitud == 'imprimir'){
        $this->procesar_facturas($valores_id);
      }elseif(!empty($valores_id[0]) AND $solicitud == 'email'){
         $this->enviar_email($valores_id[0]); 
      }
   }

   // Corregir el Bug de fpdf con el Simbolo del Euro ---> €
   public function ckeckEuro($cadena)
   {
      $mostrar = $this->show_precio($cadena, $this->factura->coddivisa);
      $pos = strpos($mostrar, '€');
      if ($pos !== false)
      {
         if (FS_POS_DIVISA == 'right')
         {
            return number_format($cadena, FS_NF0, FS_NF1, FS_NF2) . ' ' . EEURO;
         }
         else
         {
            return EEURO . ' ' . number_format($cadena, FS_NF0, FS_NF1, FS_NF2);
         }
      }
      return $mostrar;
   }

   public function procesar_facturas($valores_id, $archivo=FALSE){
        if(!empty($valores_id)){
            if(ob_get_status()){
                ob_end_clean();
            }
            $pdf_doc = new PDF_MC_Table('P', 'mm', 'letter');
            $pdf_doc->SetTitle('Facturas de Venta' );
            $pdf_doc->SetSubject('Facturas de Venta para clientes');
            $pdf_doc->SetAuthor($this->empresa->nombre);
            $pdf_doc->SetCreator('FacturaSctipts V_' . $this->version());
            
            $this->archivo = $archivo;
            $contador = 0;
            $this->factura = FALSE;
            foreach($valores_id as $id)
            {
              $factura = new factura_cliente();
              $this->factura = $factura->get($id);
              if($this->factura){
                  $ncf_datos = new ncf_ventas();
                  $valores = $ncf_datos->get_ncf($this->empresa->id, $this->factura->idfactura, $this->factura->codcliente, $this->factura->fecha);
                  $ncf_tipo = new ncf_tipo();
                  $tipo_comprobante = $ncf_tipo->get($valores->tipo_comprobante);
                  $this->factura->ncf = $valores->ncf;
                  $this->factura->ncf_afecta = $valores->ncf_modifica;
                  $this->factura->estado = $valores->estado; 
                  $this->factura->tipo_comprobante = $tipo_comprobante->descripcion;
                  if($this->distrib_transporte){
                    $transporte = $this->distrib_transporte->get($this->empresa->id, $this->factura->idfactura, $this->factura->codalmacen);
                    $this->idtransporte = (isset($transporte[0]->idtransporte))?str_pad($transporte[0]->idtransporte,10,"0",STR_PAD_LEFT):false;
                  }
                  $cliente = new cliente();
                  $this->cliente = $cliente->get($this->factura->codcliente);
                  $this->generar_pdf($pdf_doc);
                  $contador++;
              }
              
            }
            // Damos salida al archivo PDF
            if($this->archivo){
                 if (!file_exists('tmp/' . FS_TMP_NAME . 'enviar')){
                    mkdir('tmp/' . FS_TMP_NAME . 'enviar');
                 }
                 $pdf_doc->Output('tmp/' . FS_TMP_NAME . 'enviar/' . $archivo, 'F');
              }else{
                 $pdf_doc->Output();
              }

            if (!$this->factura)
            {
               $this->new_error_msg("¡Factura de cliente no encontrada!");
            }
        }
   }
   
    private function enviar_email($doc, $tipo='ncf')
   {
      $factura = new factura_cliente();
      $factura_enviar = $factura->get($doc);
      if( $this->empresa->can_send_mail() )
      {
         if( $_POST['email'] != $this->cliente->email AND isset($_POST['guardar']) )
         {
            $this->cliente->email = $_POST['email'];
            $this->cliente->save();
         }
         
         $filename = 'factura_'.$factura_enviar->numero2.'.pdf';
         if($tipo == 'ncf'){
             $this->procesar_facturas(array($factura_enviar->idfactura), $filename);
         }
         
         
         if( file_exists('tmp/'.FS_TMP_NAME.'enviar/'.$filename) )
         {
            $mail = $this->empresa->new_mail();
            $mail->FromName = $this->user->get_agente_fullname();
            $mail->addReplyTo($_POST['de'], $mail->FromName);
            
            $mail->addAddress($_POST['email'], $this->cliente->razonsocial);
            if($_POST['email_copia'])
            {
               if( isset($_POST['cco']) )
               {
                  $mail->addBCC($_POST['email_copia'], $this->cliente->razonsocial);
               }
               else
               {
                  $mail->addCC($_POST['email_copia'], $this->cliente->razonsocial);
               }
            }
            $mail->Subject = $this->empresa->nombre . ': Su factura '.$this->factura->codigo;
            
            $mail->AltBody = $_POST['mensaje'];
            $mail->msgHTML( nl2br($_POST['mensaje']) );
            $mail->isHTML(TRUE);
            
            $mail->addAttachment('tmp/'.FS_TMP_NAME.'enviar/'.$filename);
            if( is_uploaded_file($_FILES['adjunto']['tmp_name']) )
            {
               $mail->addAttachment($_FILES['adjunto']['tmp_name'], $_FILES['adjunto']['name']);
            }
            
            if( $mail->smtpConnect($this->empresa->smtp_options()) )
            {
               if( $mail->send() )
               {
                  $this->template = 'ventas_imprimir';
                  $this->new_message('Mensaje enviado correctamente.');
                  
                  /// nos guardamos la fecha de envío
                     $factura_enviar->femail = $this->today();
                     $factura_enviar->save();
                  
                  $this->empresa->save_mail($mail);
               }
               else
                  $this->new_error_msg("Error al enviar el email: " . $mail->ErrorInfo);
            }
            else
               $this->new_error_msg("Error al enviar el email: " . $mail->ErrorInfo);
            
            unlink('tmp/'.FS_TMP_NAME.'enviar/'.$filename);
         }
         else
            $this->new_error_msg('Imposible generar el PDF.');
      }
   }

   public function generar_pdf($pdf_doc)
   {
       
      if(!empty($pdf_doc)){
        ///// INICIO - Factura Detallada
        /// Creamos el PDF y escribimos sus metadatos

        $pdf_doc->StartPageGroup();
        $pdf_doc->AliasNbPages();
        $pdf_doc->SetAutoPageBreak(true, 40);
        $pdf_doc->lineaactual = 0;
        $pdf_doc->fdf_observaciones = "";

        // Definimos el color de relleno (gris, rojo, verde, azul)
        if($this->rd_setup['rd_imprimir_bn']=='FALSE'){
            $pdf_doc->SetColorRelleno('gris');
        }else{
            $pdf_doc->SetColorRelleno('blanco');
        }
        
        /// Definimos todos los datos de la cabecera de la factura
        /// Datos de la empresa
        
        $agente = new agente();
        $vendedor = $this->agente->get($this->factura->codagente);
        $vender = substr($vendedor->nombre, 0,1).substr($vendedor->apellidos,0,1);
        $pdf_doc->fde_nombre = $this->empresa->nombre;
        $pdf_doc->fde_FS_CIFNIF = FS_CIFNIF;
        $pdf_doc->fde_cifnif = $this->empresa->cifnif;
        $pdf_doc->fde_direccion = $this->empresa->direccion;
        $pdf_doc->fde_codpostal = $this->empresa->codpostal;
        $pdf_doc->fde_ciudad = $this->empresa->ciudad;
        $pdf_doc->fde_provincia = $this->empresa->provincia;
        $pdf_doc->fde_telefono = 'Teléfono: ' . $this->empresa->telefono;
        $pdf_doc->fde_fax = 'Fax: ' . $this->empresa->fax;
        $pdf_doc->fde_email = $this->empresa->email;
        $pdf_doc->fde_web = $this->empresa->web;
        $pdf_doc->fde_vendedor = 'Vendedor: ('.$vender.')'; //Mostrando iniciales del vendedor.
        $pdf_doc->fde_ruta = 'Ruta: '.$this->factura->apartado;
        $pdf_doc->fde_piefactura = $this->empresa->pie_factura;
        
        /// Insertamos el Logo y Marca de Agua si esta configurado así
        if( file_exists(FS_MYDOCS.'images/logo.png') AND ($this->rd_setup['rd_imprimir_logo']=='TRUE'))
        {
           $pdf_doc->fdf_verlogotipo = '1'; // 1/0 --> Mostrar Logotipo
           $pdf_doc->fdf_Xlogotipo = '15'; // Valor X para Logotipo
           $pdf_doc->fdf_Ylogotipo = '35'; // Valor Y para Logotipo
           $pdf_doc->fdf_vermarcaagua = '1'; // 1/0 --> Mostrar Marca de Agua
           $pdf_doc->fdf_Xmarcaagua = '25'; // Valor X para Marca de Agua
           $pdf_doc->fdf_Ymarcaagua = '110'; // Valor Y para Marca de Agua
        }
        elseif( file_exists(FS_MYDOCS.'images/logo.jpg') AND ($this->rd_setup['rd_imprimir_logo']=='TRUE'))
        {
           $pdf_doc->fdf_verlogotipo = '1'; // 1/0 --> Mostrar Logotipo
           $pdf_doc->fdf_Xlogotipo = '15'; // Valor X para Logotipo
           $pdf_doc->fdf_Ylogotipo = '35'; // Valor Y para Logotipo
           $pdf_doc->fdf_vermarcaagua = '1'; // 1/0 --> Mostrar Marca de Agua
           $pdf_doc->fdf_Xmarcaagua = '25'; // Valor X para Marca de Agua
           $pdf_doc->fdf_Ymarcaagua = '110'; // Valor Y para Marca de Agua
        }
        else
        {
           $pdf_doc->fdf_verlogotipo = '0';
           $pdf_doc->fdf_Xlogotipo = '0';
           $pdf_doc->fdf_Ylogotipo = '0';
           $pdf_doc->fdf_vermarcaagua = '0';
           $pdf_doc->fdf_Xmarcaagua = '0';
           $pdf_doc->fdf_Ymarcaagua = '0';
        }

        // Tipo de Documento
        $pdf_doc->fdf_tipodocumento = $this->factura->tipo_comprobante; // (FACTURA, FACTURA PROFORMA, ¿ALBARAN, PRESUPUESTO?...)
        $pdf_doc->fdf_codigo = $this->factura->ncf;
        $pdf_doc->fdf_codigorect = $this->factura->ncf_afecta;
        $pdf_doc->fdf_estado = ($this->factura->estado)?"":"DOCUMENTO ANULADO";

        // Fecha, Codigo Cliente y observaciones de la factura
        $pdf_doc->fdf_fecha = $this->factura->fecha;
        $pdf_doc->fdf_codcliente = $this->factura->codcliente;
        $pdf_doc->fdf_observaciones = utf8_decode( $this->fix_html($this->factura->observaciones));
        

        // Datos del Cliente
        $pdf_doc->fdf_nombrecliente = $this->fix_html($this->factura->nombrecliente);
        $pdf_doc->fdf_FS_CIFNIF = FS_CIFNIF;
        $pdf_doc->fdf_cifnif = $this->factura->cifnif;
        $pdf_doc->fdf_direccion = $this->fix_html($this->factura->direccion);
        $pdf_doc->fdf_codpostal = $this->factura->codpostal;
        $pdf_doc->fdf_ciudad = $this->factura->ciudad;
        $pdf_doc->fdf_provincia = $this->factura->provincia;
        $pdf_doc->fdc_telefono1 = $this->cliente->telefono1;
        $pdf_doc->fdc_telefono2 = $this->cliente->telefono2;
        $pdf_doc->fdc_fax = $this->cliente->fax;
        $pdf_doc->fdc_email = $this->cliente->email;

        $pdf_doc->fdf_epago = $pdf_doc->fdf_divisa = $pdf_doc->fdf_pais = '';

        // Conduce asociado
        $pdf_doc->fdf_transporte = $this->idtransporte;
        //Si va usar distribucion se agrega el codigo de la ruta
        //$pdf_doc->fdf_ruta = $this->factura->apartado;
        
        // Forma de Pago de la Factura
        $pago = new forma_pago();
        $epago = $pago->get($this->factura->codpago);
        if ($epago) {
           $pdf_doc->fdf_epago = $epago->descripcion;
        }

        // Divisa de la Factura
        $divisa = new divisa();
        $edivisa = $divisa->get($this->factura->coddivisa);
        if ($edivisa) {
           $pdf_doc->fdf_divisa = $edivisa->descripcion;
        }

        // Pais de la Factura
        $pais = new pais();
        $epais = $pais->get($this->factura->codpais);
        if ($epais) {
           $pdf_doc->fdf_pais = $epais->nombre;
        }

        // Cabecera Titulos Columnas
        $pdf_doc->Setdatoscab(array('ARTICULO', 'DESCRIPCION', 'CANT', 'PRECIO', 'DTO', FS_IVA, 'IMPORTE'));
        $pdf_doc->SetWidths(array(25, 85,15 ,20, 18, 10, 22));
        $pdf_doc->SetAligns(array('L', 'L', 'R', 'R', 'R', 'R', 'R'));
        $pdf_doc->SetColors(array('6|47|109', '6|47|109', '6|47|109', '6|47|109', '6|47|109', '6|47|109', '6|47|109'));
        /// Agregamos la pagina inicial de la factura
         $pdf_doc->AddPage();

        /// Definimos todos los datos del PIE de la factura
        /// Lineas de IVA
        $lineas_iva = $this->factura->get_lineas_iva();
        $negativo = (!empty($this->factura->idfacturarect))?-1:1;
        if( count($lineas_iva) > 3 )
        {
           $pdf_doc->fdf_lineasiva = $lineas_iva;
        }
        else
        {
           $filaiva = array();
           $i = 0;
           foreach($lineas_iva as $li)
           {
              $i++;
              $filaiva[$i][0] = ($li->iva) ? FS_IVA . ($li->iva * $negativo) : '';
              $filaiva[$i][1] = ($li->neto) ? $this->ckeckEuro(($li->neto * $negativo)) : '';
              $filaiva[$i][2] = ($li->iva) ? ($li->iva * $negativo) . "%" : '';
              $filaiva[$i][3] = ($li->totaliva) ? $this->ckeckEuro(($li->totaliva * $negativo)) : '';
              $filaiva[$i][4] = ($li->recargo) ? $li->recargo . "%" : '';
              $filaiva[$i][5] = ($li->totalrecargo) ? $this->ckeckEuro(($li->totalrecargo * $negativo)) : '';
              $filaiva[$i][6] = ''; //// POR CREARRRRRR
              $filaiva[$i][7] = ''; //// POR CREARRRRRR
              $filaiva[$i][8] = ($li->totallinea) ? $this->ckeckEuro(($li->totallinea * $negativo)) : '';
           }

           if($filaiva)
           {
              $filaiva[1][6] = $this->factura->irpf.' %';
              $filaiva[1][7] = $this->ckeckEuro(0 - ($this->factura->totalirpf * $negativo));
           }

           $pdf_doc->fdf_lineasiva = $filaiva;
        }

        // Total factura numerico
        $pdf_doc->fdf_numtotal = $this->ckeckEuro(($this->factura->total * $negativo));

        // Total factura numeros a texto
        $pdf_doc->fdf_textotal = ($this->factura->total * $negativo);



        // Lineas de la Factura
        $lineas = $this->factura->get_lineas();
        if ($lineas) {
           $neto = 0;
           for ($i = 0; $i < count($lineas); $i++) {
              $neto += ($lineas[$i]->pvptotal * $negativo);
              $pdf_doc->neto = $this->ckeckEuro($neto);
              $articulo = new articulo();
              $art = $articulo->get($lineas[$i]->referencia);
              if ($art) {
                 $observa = "\n" . utf8_decode( $this->fix_html($art->observaciones) );
              } else {
                 //$observa = null; // No mostrar mensaje de error
                 $observa = "\n";
              }

              $lafila = array(
                   // '0' => utf8_decode($lineas[$i]->albaran_codigo() . '-' . $lineas[$i]->albaran_numero()),
                   '0' => utf8_decode($lineas[$i]->referencia),
                   '1' => utf8_decode(strtoupper($lineas[$i]->descripcion)) . $observa,
                   '2' => utf8_decode(($lineas[$i]->cantidad * $negativo)),
                   '3' => $this->ckeckEuro($lineas[$i]->pvpunitario),
                   '4' => utf8_decode($this->show_numero($lineas[$i]->dtopor, 0) . " %"),
                   '5' => utf8_decode($this->show_numero(($lineas[$i]->iva), 0) . " %"),
                  // '6' => $this->ckeckEuro($lineas[$i]->pvptotal), // Importe con Descuentos aplicados
                   '6' => $this->ckeckEuro(($lineas[$i]->total_iva() * $negativo))
               );
              $pdf_doc->Row($lafila, '1'); // Row(array, Descripcion del Articulo -- ultimo valor a imprimir)
           }
           $pdf_doc->piepagina = true;
        }
       }
   }

   private function share_extensions()
   {
      $extensiones = array(
        array(
            'name' => 'factura_ncf',
            'page_from' => __CLASS__,
            'page_to' => 'ventas_factura',
            'type' => 'pdf',
            'text' => 'Factura con NCF',
            'params' => '&solicitud=imprimir'
        ),
          array(
            'name' => 'email_factura_ncf',
            'page_from' => __CLASS__,
            'page_to' => 'ventas_factura',
            'type' => 'email',
            'text' => 'Factura con NCF',
            'params' => '&solicitud=email'
        )
      );
      foreach($extensiones as $ext){
        $fsext = new fs_extension($ext);
        $fsext->save();
      }
   }

   private function fix_html($txt)
   {
      $newt = str_replace('&lt;', '<', $txt);
      $newt = str_replace('&gt;', '>', $newt);
      $newt = str_replace('&quot;', '"', $newt);
      $newt = str_replace('&#39;', "'", $newt);
      return $newt;
   }
}
