// Funciones JavaScript para el sistema  
  
// Confirmar eliminación  
function confirmarEliminacion(mensaje = '¿Está seguro de eliminar este elemento?') {  
    return confirm(mensaje);  
}  
  
// Validar formularios  
function validarFormulario(formId) {  
    const form = document.getElementById(formId);  
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');  
      
    for (let input of inputs) {  
        if (!input.value.trim()) {  
            alert('Por favor complete todos los campos obligatorios');  
            input.focus();  
            return false;  
        }  
    }  
    return true;  
}  
  
// Filtrar tablas  
function filtrarTabla(inputId, tablaId) {  
    const input = document.getElementById(inputId);  
    const tabla = document.getElementById(tablaId);  
    const filas = tabla.getElementsByTagName('tr');  
      
    input.addEventListener('keyup', function() {  
        const filtro = this.value.toLowerCase();  
          
        for (let i = 1; i < filas.length; i++) {  
            const fila = filas[i];  
            const texto = fila.textContent.toLowerCase();  
              
            if (texto.includes(filtro)) {  
                fila.style.display = '';  
            } else {  
                fila.style.display = 'none';  
            }  
        }  
    });  
}  
  
// Toggle de checkboxes en evaluación espiritual  
function toggleCategoria(categoriaClass) {  
    const checkboxes = document.querySelectorAll('.' + categoriaClass);  
    const todosSeleccionados = Array.from(checkboxes).every(cb => cb.checked);  
      
    checkboxes.forEach(cb => {  
        cb.checked = !todosSeleccionados;  
    });  
}  
  
// Previsualizar archivo subido  
function previsualizarArchivo(input, previewId) {  
    const file = input.files[0];  
    const preview = document.getElementById(previewId);  
      
    if (file) {  
        const reader = new FileReader();  
        reader.onload = function(e) {  
            if (file.type.startsWith('image/')) {  
                preview.innerHTML = `<img src="${e.target.result}" class="img-fluid" style="max-height: 200px;">`;  
            } else {  
                preview.innerHTML = `<i class="fas fa-file"></i> ${file.name}`;  
            }  
        };  
        reader.readAsDataURL(file);  
    }  
}  
  
// Mostrar/ocultar loading  
function mostrarLoading(show = true) {  
    const loading = document.getElementById('loading');  
    if (loading) {  
        loading.style.display = show ? 'block' : 'none';  
    }  
}  
  
// Validar números en inputs  
function soloNumeros(input) {  
    input.addEventListener('input', function() {  
        this.value = this.value.replace(/[^0-9]/g, '');  
    });  
}  
  
// Auto-resize textarea  
function autoResizeTextarea(textarea) {  
    textarea.style.height = 'auto';  
    textarea.style.height = textarea.scrollHeight + 'px';  
}  
  
// Inicializar tooltips de Bootstrap  
document.addEventListener('DOMContentLoaded', function() {  
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));  
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {  
        return new bootstrap.Tooltip(tooltipTriggerEl);  
    });  
});  
  
// ── Protección contra salida sin guardar ────────────────────────
let formChanged = false;

function beforeUnloadHandler(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
}

window.addEventListener('beforeunload', beforeUnloadHandler);

function marcarCambiosFormulario() {
    // NO hace nada por defecto.
    // Cada página activa su propia protección con activarProteccionFormulario()
}

/**
 * Llama esta función DESPUÉS de que el JS de la página
 * haya terminado de inicializar los selects/campos.
 * Solo escucha eventos isTrusted (reales del usuario).
 */
function activarProteccionFormulario() {
    document.querySelectorAll('form').forEach(form => {

        form.addEventListener('submit', () => {
            formChanged = false;
            window.removeEventListener('beforeunload', beforeUnloadHandler);
        });

        form.querySelectorAll('input, select, textarea').forEach(campo => {
            campo.addEventListener('input', (e) => {
                if (e.isTrusted) formChanged = true;
            });
            campo.addEventListener('change', (e) => {
                if (e.isTrusted) formChanged = true;
            });
        });
    });
}
  
// Guardar automáticamente (opcional)  
function autoGuardar(formId, url, intervalo = 30000) {  
    setInterval(() => {  
        const form = document.getElementById(formId);  
        if (form && formChanged) {  
            const formData = new FormData(form);  
            formData.append('auto_save', '1');  
              
            fetch(url, {  
                method: 'POST',  
                body: formData  
            })  
            .then(response => response.json())  
            .then(data => {  
                if (data.success) {  
                    console.log('Guardado automático exitoso');  
                    formChanged = false;  
                }  
            })  
            .catch(error => console.error('Error en guardado automático:', error));  
        }  
    }, intervalo);  
}  