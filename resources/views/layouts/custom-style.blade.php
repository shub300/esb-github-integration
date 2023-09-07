<style>
    .main-menu .navbar-header .navbar-brand .brand-logo img {
       max-width: 170px !important;
    }
   .btn-primary:hover{
       background-color:{{ isset($org_style->primary_button_hover_color) ? $org_style->primary_button_hover_color.' !important' : ''}};
   }, 
   .btn-primary:focus, 
   .btn-primary:active, 
   .btn-primary.active { 
       color: #ffffff; 
       background-color:#2C6FA8 !important;
       border:1px solid #2C6FA8 !important;
   } 
   .main-menu.menu-light .navigation li a {
       color: {{ isset($org_style->text_links_color) ? $org_style->text_links_color.' !important' : '' }};
   }
   .main-menu.menu-light .navigation>li.active>a {
       background: {{ isset($org_style->active_menu_bg_color) ? $org_style->active_menu_bg_color.' !important' : 'rgb(115 103 240 / 12%)'}};
       color: {{ isset($org_style->active_menu_text_color) ? $org_style->active_menu_text_color.' !important' : '#2C6FA8' }};
       }
       .btn-primary{
           background-color: {{ isset($org_style->primary_button_color) ? $org_style->primary_button_color.' !important' : 'btn-primary'}};
       }
       .btn-warning{
           background-color: #F19629 !important;
       }
       .btn-warning:hover{
           background-color: #C7720D !important;
       }, 
   
       a{
           color: {{ isset($org_style->text_links_color) ? $org_style->text_links_color.' !important' : '' }};
       }
       /* .form-control{
           border: 1px solid #BBBBBB !important;
           color: {{ isset($org_style->active_menu_text_color) ? $org_style->active_menu_text_color.' !important' : '' }};
       } */

       /* input:focus { */
           /* outline: 1px solid {{ isset($org_style->input_border_color) ? $org_style->input_border_color.' !important' : '' }}; */
       /* } */
       /* select:focus { */
           /* outline: 1px solid {{ isset($org_style->input_border_color) ? $org_style->input_border_color.' !important' : '' }}; */
       /* } */
       
      .setup-integration-txt{
        color : {{ isset($org_style->active_menu_text_color) ? $org_style->active_menu_text_color.' !important' : '' }};
      }

      .card .card-header{
           background-color: {{ isset($org_style->card_bg_color) ? $org_style->card_bg_color.' !important' : '' }};
      }
      .app-icon .icon{
           border: 1px solid #999999 !important;
      }
      .connect-app{
           border-top: 1px solid #999999 !important;
      }
      .border-top-blue {
           border-top: 1px solid #E4E6E8 !important;
       }
       /* .table:not(.table-dark):not(.table-light) tfoot:not(.thead-dark) th, .table:not(.table-dark):not(.table-light) thead:not(.thead-dark) th {
           background-color: {{ isset($org_style->card_bg_color) ? $org_style->card_bg_color.' !important' : '' }};
       } */
       /* .table thead th {
           border-bottom: 2px solid #008896;
       } */
       /* .nav-tabs .nav-link:after {
           background: {{ isset($org_style->input_border_color) ? $org_style->input_border_color.' !important' : '' }};
       } */
       .primary-btn-style{
           color: {{ isset($org_style->primary_button_color) ? '#FFF !important' : ''}};
           background-color: {{ isset($org_style->primary_button_color) ? $org_style->primary_button_color.' !important' : ''}};
           border: {{ isset($org_style->primary_button_color) ? $org_style->primary_button_color.' !important' : ''}};
       }
       .primary-btn-style:hover{
           background-color:{{ isset($org_style->primary_button_hover_color) ? $org_style->primary_button_hover_color.' !important' : ''}};
       }
       .secondary-btn-style{
           border:  {{ isset($org_style->secondary_button_color) ? '1px solid '.$org_style->secondary_button_color.' !important' : '' }};
           background-color: {{ isset($org_style->secondary_button_color) ? '#FFFFFF'.' !important': '' }};
           color:{{ isset($org_style->active_menu_text_color) ? $org_style->active_menu_text_color.' !important' : '' }};
       }
       .secondary-btn-style:hover{
           background-color: {{ isset($org_style->card_bg_color) ? $org_style->card_bg_color.' !important' : '' }};
       }
       .custom-switch .custom-control-label::before{
           background-color: {{ isset($org_style->card_bg_color) ? $org_style->card_bg_color.' !important' : '' }};
       }
       .custom-control-input:checked~.custom-control-label::before {
           border-color: {{ isset($org_style->input_border_color) ? $org_style->input_border_color.' !important' : '' }};
           background-color: {{ isset($org_style->input_border_color) ? $org_style->input_border_color.' !important' : '' }};
       }
       .alert-warning{
           background-color: {{ isset($org_style->active_menu_text_color) ? 'transparent !important' : '' }};
       }
       .bg-success{
           background-color: #81C926 !important;
       }
       .bg-secondary{
           background-color: #999999 !important;
       }
       .text-success{
           color: #81C926 !important;
       }
       .badge-success {
           background-color: #81C926 !important;
       }
       .badge-danger {
           background-color: #D91C03 !important;
       }
       
       .work-item-card:hover {
           box-shadow:{{ isset($org_style->active_menu_bg_color) ? 'none !important' : ''}}; ;
           background-color: {{ isset($org_style->active_menu_bg_color) ? $org_style->active_menu_bg_color.' !important' : ''}};
       }
       .tooltip-inner{
           color: #fff !important;
       }

       .toast-success {
           color: #fff !important;
       }

       .toast-error {
           color: #fff !important;
       }

       .toast-info {
       color: #fff !important;
       }

       .toast-warning {
           color: #fff !important;
       }
       
       .select2-container--default .select2-results__option--highlighted[aria-selected] {
           background-color:{{ isset($org_style->active_menu_bg_color) ? $org_style->active_menu_bg_color.' !important' : ''}};
           /* color: #222222 !important; */
           color: {{ isset($org_style->text_links_color) ? $org_style->text_links_color.' !important' : '' }}
       }
       
       p,h1,h2,h3,h4,h5,h6,small,div,li{
           color: {{ isset($org_style->active_menu_text_color) ? $org_style->active_menu_text_color.' !important' : '' }};
       }
       .esb-alert-warning{
           color: {{ isset($org_style->active_menu_text_color) ? $org_style->active_menu_text_color.' !important' : '' }};
       }
       /* .breadcrumb:not([class*=breadcrumb-]) .breadcrumb-item+.breadcrumb-item:before {
			content: '>' !important;
		} */
       
       /* Sidebar Accounting css start */
    .main-menu .navbar-header {
        width: {{ isset($org_style->active_menu_bg_color) ? 'auto !important' : ''}};
        height: {{ isset($org_style->active_menu_bg_color) ? 'auto !important' : ''}};
        padding: {{ isset($org_style->active_menu_bg_color) ? '0 !important' : ''}};
    }

    .main-menu .navbar-header .navbar-brand {
        margin-left: {{ isset($org_style->active_menu_bg_color) ? '40px !important' : ''}};
        margin-top: {{ isset($org_style->active_menu_bg_color) ? '1.32rem !important' : ''}};
    }

    .main-menu .navbar-header .navbar-brand h2 {
        font-size: {{ isset($org_style->active_menu_bg_color) ? '16px !important' : ''}};
    }
    /* Sidebar Accounting css start */

    /* to remove extra space from top */
    html .content.app-content {
        padding: {{ isset($org_style->active_menu_bg_color) ? 'calc(2rem + 0.01rem + 0.01rem) 2rem 0 !important' : ''}};
    }

    .navbar-floating .header-navbar-shadow {
        padding-top: {{ isset($org_style->active_menu_bg_color) ? '0 !important' : ''}};
        height: {{ isset($org_style->active_menu_bg_color) ? '0 !important' : ''}};
        position: {{ isset($org_style->active_menu_bg_color) ? 'relative !important' : ''}};
    }
    /* to remove extra space from top */


    /*start iframe zoom issue in extensiv */
    /* @media (min-width: 768px) {
    .content-wrapper {
        transform: {{ isset($org_style->active_menu_bg_color) ? 'scale(0.92) !important' : ''}};
        margin-left: {{ isset($org_style->active_menu_bg_color) ? '-50px !important' : ''}};
    }
    .app-content{
        padding: {{ isset($org_style->active_menu_bg_color) ? '0 20px !important' : ''}};
    }
    } */
    /*end iframe zoom issue in extensiv */
</style>